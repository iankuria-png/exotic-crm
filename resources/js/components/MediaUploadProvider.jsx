import React, { createContext, useCallback, useContext, useMemo, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import { useToast } from './ToastProvider';

const MediaUploadContext = createContext(null);

export const MEDIA_UPLOAD_LIMITS = {
    imageMaxBytes: 5 * 1024 * 1024,
    videoMaxBytes: 50 * 1024 * 1024,
    largeImageBytes: 4 * 1024 * 1024,
    largeVideoBytes: 35 * 1024 * 1024,
};

function fileExtension(file) {
    const name = String(file?.name || '').toLowerCase();
    const match = name.match(/\.([a-z0-9]+)$/);

    return match ? match[1] : '';
}

function formatBytes(bytes) {
    const value = Number(bytes || 0);
    if (!Number.isFinite(value) || value <= 0) return '0MB';

    return `${(value / (1024 * 1024)).toFixed(value >= 10 * 1024 * 1024 ? 0 : 1)}MB`;
}

function isImageUploadFile(file) {
    const mime = String(file?.type || '').toLowerCase();
    const ext = fileExtension(file);

    return ['image/jpeg', 'image/png', 'image/webp'].includes(mime)
        || ['jpg', 'jpeg', 'png', 'webp'].includes(ext);
}

function isVideoUploadFile(file) {
    const mime = String(file?.type || '').toLowerCase();
    const ext = fileExtension(file);

    return mime === 'video/mp4' || ext === 'mp4';
}

function describeMediaUploadFiles(files) {
    const list = Array.isArray(files) ? files : [];
    if (list.length === 0) return 'Media upload';
    if (list.length === 1) return list[0]?.name || '1 file';

    return `${list.length} images`;
}

export function getMediaUploadPreflight(files, setMain = false) {
    const list = Array.isArray(files) ? files.filter(Boolean) : [];
    const guidance = [];
    const errors = [];
    const hasMultiple = list.length > 1;
    const hasVideo = list.some((file) => isVideoUploadFile(file));

    if (hasMultiple && hasVideo) {
        errors.push('Multiple uploads are available for images only. Upload videos one at a time.');
    }

    list.forEach((file) => {
        const name = file?.name || 'Selected file';
        const size = Number(file?.size || 0);
        const isImage = isImageUploadFile(file);
        const isVideo = isVideoUploadFile(file);

        if (!isImage && !isVideo) {
            errors.push(`${name} must be a JPG, PNG, WEBP, or MP4 file.`);
            return;
        }

        if (isVideo && setMain) {
            errors.push('Videos cannot be set as the main profile image.');
        }

        if (isImage && size > MEDIA_UPLOAD_LIMITS.imageMaxBytes) {
            errors.push(`${name} is ${formatBytes(size)}. Images must be 5MB or smaller.`);
        } else if (isImage && size >= MEDIA_UPLOAD_LIMITS.largeImageBytes) {
            guidance.push(`${name} is large. Compressing to WEBP before upload may be faster.`);
        }

        if (isVideo && size > MEDIA_UPLOAD_LIMITS.videoMaxBytes) {
            errors.push(`${name} is ${formatBytes(size)}. MP4 videos must be 50MB or smaller.`);
        } else if (isVideo && size >= MEDIA_UPLOAD_LIMITS.largeVideoBytes) {
            guidance.push(`${name} is a large video. H.264 MP4 under 30MB usually uploads faster.`);
        }
    });

    return {
        ok: errors.length === 0,
        errors,
        guidance,
        label: describeMediaUploadFiles(list),
    };
}

export function MediaUploadProvider({ children }) {
    const queryClient = useQueryClient();
    const toast = useToast();
    const [uploads, setUploads] = useState([]);

    const dismissUpload = useCallback((uploadId) => {
        setUploads((current) => current.filter((upload) => upload.id !== uploadId));
    }, []);

    const uploadToWordPress = useCallback((uploadId, uploadFiles, setMain, clientId) => {
        const formData = new FormData();
        if (uploadFiles.length === 1) {
            formData.append('file', uploadFiles[0]);
        } else {
            uploadFiles.forEach((file) => {
                formData.append('files[]', file);
            });
        }
        formData.append('set_main', setMain ? '1' : '0');
        formData.append('reason', 'Background media upload from client detail');

        void api.post(`/crm/clients/${clientId}/media`, formData, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        }).then((response) => {
            queryClient.invalidateQueries({ queryKey: ['client', String(clientId)] });
            queryClient.invalidateQueries({ queryKey: ['client-media', String(clientId)] });
            queryClient.invalidateQueries({ queryKey: ['clients'] });

            const uploadedCount = Number(response?.data?.uploaded_count || 0);
            const successMessage = uploadedCount > 1
                ? `${uploadedCount} images uploaded to WordPress.`
                : 'Media uploaded to WordPress.';

            setUploads((current) => current.map((upload) => (
                upload.id === uploadId
                    ? { ...upload, status: 'success', message: successMessage, files: [] }
                    : upload
            )));
            toast.success(successMessage);
            window.setTimeout(() => dismissUpload(uploadId), 30000);
        }).catch((error) => {
            const failureMessage = error?.response?.data?.message || 'Upload failed. Retry from the Media tab.';
            setUploads((current) => current.map((upload) => (
                upload.id === uploadId
                    ? { ...upload, status: 'failed', message: failureMessage }
                    : upload
            )));
            toast.warning(failureMessage, { duration: 7000 });
        });
    }, [dismissUpload, queryClient, toast]);

    const startClientMediaUpload = useCallback(({ clientId, clientName = '', files, setMain = false }) => {
        const uploadFiles = Array.isArray(files) ? files.filter(Boolean) : [];
        const preflight = getMediaUploadPreflight(uploadFiles, setMain);
        if (uploadFiles.length === 0) {
            return { queued: false, error: 'Select at least one file first.' };
        }

        if (!preflight.ok) {
            toast.warning(preflight.errors[0] || 'Media upload cannot start with the selected files.');
            return { queued: false, error: preflight.errors[0] || 'Invalid media upload.' };
        }

        const uploadId = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
        const entry = {
            id: uploadId,
            clientId: String(clientId),
            clientName,
            label: preflight.label,
            files: uploadFiles,
            setMain,
            status: 'uploading',
            message: 'Uploading in the background',
            attempts: 1,
            createdAt: Date.now(),
        };

        setUploads((current) => [entry, ...current]);
        toast.info(`${preflight.label} uploading in the background.`);
        uploadToWordPress(uploadId, uploadFiles, setMain, clientId);

        return { queued: true, uploadId };
    }, [toast, uploadToWordPress]);

    const retryUpload = useCallback((uploadId) => {
        const upload = uploads.find((item) => item.id === uploadId);
        if (!upload || upload.status !== 'failed' || !upload.files?.length) {
            return;
        }

        const preflight = getMediaUploadPreflight(upload.files, upload.setMain);
        if (!preflight.ok) {
            toast.warning(preflight.errors[0] || 'Media upload cannot be retried.');
            return;
        }

        setUploads((current) => current.map((item) => (
            item.id === uploadId
                ? {
                    ...item,
                    status: 'uploading',
                    message: 'Retrying upload',
                    attempts: Number(item.attempts || 1) + 1,
                }
                : item
        )));
        toast.info(`Retrying ${upload.label}.`);
        uploadToWordPress(upload.id, upload.files, upload.setMain, upload.clientId);
    }, [toast, uploadToWordPress, uploads]);

    const value = useMemo(() => {
        const activeUploads = uploads.filter((upload) => upload.status === 'uploading');
        const failedUploads = uploads.filter((upload) => upload.status === 'failed');

        return {
            uploads,
            activeUploads,
            failedUploads,
            activeCount: activeUploads.length,
            failedCount: failedUploads.length,
            startClientMediaUpload,
            retryUpload,
            dismissUpload,
            uploadsForClient: (clientId) => uploads.filter((upload) => upload.clientId === String(clientId)),
        };
    }, [dismissUpload, retryUpload, startClientMediaUpload, uploads]);

    return (
        <MediaUploadContext.Provider value={value}>
            {children}
        </MediaUploadContext.Provider>
    );
}

export function useMediaUploads() {
    const context = useContext(MediaUploadContext);
    if (!context) {
        throw new Error('useMediaUploads must be used within MediaUploadProvider.');
    }

    return context;
}
