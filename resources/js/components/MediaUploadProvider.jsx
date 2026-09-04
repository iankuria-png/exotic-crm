import React, { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';
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

export function isImageUploadFile(file) {
    const mime = String(file?.type || '').toLowerCase();
    const ext = fileExtension(file);

    return ['image/jpeg', 'image/png', 'image/webp'].includes(mime)
        || ['jpg', 'jpeg', 'png', 'webp'].includes(ext);
}

export function isVideoUploadFile(file) {
    const mime = String(file?.type || '').toLowerCase();
    const ext = fileExtension(file);

    return mime === 'video/mp4' || ext === 'mp4';
}

function describeMediaUploadFiles(files) {
    const list = Array.isArray(files) ? files : [];
    if (list.length === 0) return 'Media upload';
    if (list.length === 1) return list[0]?.name || '1 file';

    const imageCount = list.filter((file) => isImageUploadFile(file)).length;
    const videoCount = list.filter((file) => isVideoUploadFile(file)).length;
    const parts = [];

    if (imageCount > 0) {
        parts.push(`${imageCount} image${imageCount === 1 ? '' : 's'}`);
    }

    if (videoCount > 0) {
        parts.push(`${videoCount} video${videoCount === 1 ? '' : 's'}`);
    }

    return parts.length > 0 ? parts.join(', ') : `${list.length} files`;
}

function uploadErrorDetails(error) {
    const status = Number(error?.response?.status || 0);
    const serverMessage = String(error?.response?.data?.message || '').trim();

    if (status === 413) {
        return {
            status,
            message: serverMessage || 'This file is too large for the server. Ask an admin to raise the upload limit.',
            stopBatch: false,
        };
    }

    if ([401, 419].includes(status)) {
        return {
            status,
            message: serverMessage || 'Your CRM session expired. Sign in again, then retry this upload.',
            stopBatch: true,
        };
    }

    if (status === 403) {
        return {
            status,
            message: serverMessage || 'You do not have permission to upload media for this client.',
            stopBatch: true,
        };
    }

    if (serverMessage) {
        return {
            status,
            message: serverMessage,
            stopBatch: false,
        };
    }

    if (!error?.response) {
        return {
            status: 0,
            message: 'Network connection lost while uploading. Check your connection, then retry.',
            stopBatch: false,
        };
    }

    return {
        status,
        message: 'Upload failed. Retry this file.',
        stopBatch: false,
    };
}

export function getMediaUploadPreflight(files, setMain = false) {
    const list = Array.isArray(files) ? files.filter(Boolean) : [];
    const guidance = [];
    const errors = [];

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

const MEDIA_DELETE_CHUNK_SIZE = 5;

function deleteErrorDetails(error) {
    const status = Number(error?.response?.status || 0);
    const serverMessage = String(error?.response?.data?.message || '').trim();

    if ([401, 419].includes(status)) {
        return { status, message: serverMessage || 'Your CRM session expired. Sign in again, then retry.', stopBatch: true };
    }

    if (status === 403) {
        return { status, message: serverMessage || 'You do not have permission to delete media for this client.', stopBatch: true };
    }

    if (!error?.response) {
        return { status: 0, message: 'Network connection lost while deleting. Check your connection, then retry.', stopBatch: true };
    }

    return { status, message: serverMessage || 'Delete failed. Retry these files.', stopBatch: false };
}

function chunkList(list, size) {
    const chunks = [];
    for (let index = 0; index < list.length; index += size) {
        chunks.push(list.slice(index, index + size));
    }

    return chunks;
}

export function MediaUploadProvider({ children }) {
    const queryClient = useQueryClient();
    const toast = useToast();
    const [uploads, setUploads] = useState([]);
    const activeClientUploadsRef = useRef(new Set());

    const dismissUpload = useCallback((uploadId) => {
        setUploads((current) => current.filter((upload) => {
            if (upload.id !== uploadId) {
                return true;
            }

            if (upload.status === 'uploading') {
                toast.warning('Wait for this media upload to finish before dismissing it.');
                return true;
            }

            return false;
        }));
    }, [toast]);

    const clearClientUploadLock = useCallback((clientId) => {
        activeClientUploadsRef.current.delete(String(clientId));
    }, []);

    const updateUpload = useCallback((uploadId, updater) => {
        setUploads((current) => current.map((upload) => (
            upload.id === uploadId ? updater(upload) : upload
        )));
    }, []);

    const updateUploadItem = useCallback((uploadId, itemId, patch) => {
        updateUpload(uploadId, (upload) => ({
            ...upload,
            items: upload.items.map((item) => (
                item.id === itemId
                    ? { ...item, ...(typeof patch === 'function' ? patch(item) : patch) }
                    : item
            )),
        }));
    }, [updateUpload]);

    const finalizeUpload = useCallback((uploadId, clientId, summary) => {
        clearClientUploadLock(clientId);

        const successCount = Number(summary?.successCount || 0);
        const failedCount = Number(summary?.failedCount || 0);
        const totalCount = Number(summary?.totalCount || successCount + failedCount);
        const stopMessage = String(summary?.stopMessage || '').trim();
        const finalStatus = failedCount > 0 ? 'failed' : 'success';
        const finalMessage = failedCount > 0
            ? `${successCount} of ${totalCount} uploaded. ${failedCount} failed.${stopMessage ? ` ${stopMessage}` : ''}`
            : `${successCount} media file${successCount === 1 ? '' : 's'} uploaded to WordPress.`;

        setUploads((current) => current.map((upload) => {
            if (upload.id !== uploadId) {
                return upload;
            }

            return {
                ...upload,
                status: finalStatus,
                message: finalMessage,
            };
        }));

        queryClient.invalidateQueries({ queryKey: ['client', String(clientId)] });
        queryClient.invalidateQueries({ queryKey: ['client-media', String(clientId)] });
        queryClient.invalidateQueries({ queryKey: ['clients'] });

        if (finalStatus === 'success') {
            toast.success(finalMessage);
            window.setTimeout(() => dismissUpload(uploadId), 30000);
        } else {
            toast.warning(finalMessage, { duration: 7000 });
        }
    }, [clearClientUploadLock, dismissUpload, queryClient, toast]);

    const uploadItemsToWordPress = useCallback(async (uploadId, uploadItems, setMainItemId, clientId, summaryBase = {}) => {
        const runResults = new Map(uploadItems.map((item) => [item.id, 'pending']));
        let batchStopMessage = '';

        try {
            for (let index = 0; index < uploadItems.length; index += 1) {
                const item = uploadItems[index];

                if (batchStopMessage) {
                    runResults.set(item.id, 'failed');
                    updateUploadItem(uploadId, item.id, {
                        status: 'failed',
                        message: `Skipped after upload was interrupted: ${batchStopMessage}`,
                    });
                    continue;
                }

                const formData = new FormData();
                formData.append('file', item.file);
                formData.append('set_main', item.id === setMainItemId ? '1' : '0');
                formData.append('reason', 'Background media upload from CRM');

                updateUpload(uploadId, (upload) => ({
                    ...upload,
                    status: 'uploading',
                    message: `Uploading ${index + 1} of ${uploadItems.length}: ${item.name}`,
                    items: upload.items.map((currentItem) => (
                        currentItem.id === item.id
                            ? { ...currentItem, status: 'uploading', percent: 0, message: 'Uploading' }
                            : currentItem
                    )),
                }));

                try {
                    await api.post(`/crm/clients/${clientId}/media`, formData, {
                        headers: {
                            'Content-Type': 'multipart/form-data',
                        },
                        onUploadProgress: (progressEvent) => {
                            const total = Number(progressEvent.total || item.file?.size || 0);
                            const loaded = Number(progressEvent.loaded || 0);
                            const percent = total > 0
                                ? Math.min(100, Math.round((loaded / total) * 100))
                                : 0;

                            updateUploadItem(uploadId, item.id, {
                                percent,
                                message: percent >= 100 ? 'Processing' : `${percent}% uploaded`,
                            });
                        },
                    });

                    runResults.set(item.id, 'success');
                    updateUploadItem(uploadId, item.id, {
                        status: 'success',
                        percent: 100,
                        message: 'Uploaded',
                    });
                } catch (error) {
                    const details = uploadErrorDetails(error);
                    runResults.set(item.id, 'failed');
                    updateUploadItem(uploadId, item.id, {
                        status: 'failed',
                        errorStatus: details.status,
                        message: details.message,
                    });

                    if (details.stopBatch) {
                        batchStopMessage = details.message;
                    }
                }
            }
        } catch (error) {
            const details = uploadErrorDetails(error);
            batchStopMessage = details.message;
            uploadItems.forEach((item) => {
                if (runResults.get(item.id) !== 'success') {
                    runResults.set(item.id, 'failed');
                    updateUploadItem(uploadId, item.id, {
                        status: 'failed',
                        errorStatus: details.status,
                        message: details.message,
                    });
                }
            });
        } finally {
            const processedSuccessCount = Array.from(runResults.values()).filter((status) => status === 'success').length;
            const processedFailedCount = Array.from(runResults.values()).filter((status) => status === 'failed').length;

            finalizeUpload(uploadId, clientId, {
                totalCount: Number(summaryBase.totalCount || uploadItems.length),
                successCount: Number(summaryBase.existingSuccessCount || 0) + processedSuccessCount,
                failedCount: processedFailedCount,
                stopMessage: batchStopMessage,
            });
        }
    }, [finalizeUpload, updateUpload, updateUploadItem]);

    const uploadToWordPress = useCallback((uploadId, uploadItems, setMainItemId, clientId, summaryBase = {}) => {
        void uploadItemsToWordPress(uploadId, uploadItems, setMainItemId, clientId, summaryBase);
    }, [uploadItemsToWordPress]);

    const buildUploadItems = useCallback((uploadId, uploadFiles) => (
        uploadFiles.map((file, index) => ({
            id: `${uploadId}-${index}`,
            file,
            name: file?.name || `File ${index + 1}`,
            isVideo: isVideoUploadFile(file),
            status: 'pending',
            percent: 0,
            message: 'Waiting',
        }))
    ), []);

    const startUploadRun = useCallback((uploadId, items, setMainItemId, clientId, summaryBase = {}) => {
        const clientKey = String(clientId);
        activeClientUploadsRef.current.add(clientKey);
        uploadToWordPress(uploadId, items, setMainItemId, clientKey, summaryBase);
    }, [uploadToWordPress]);

    const startClientMediaUpload = useCallback(({ clientId, clientName = '', files, setMain = false }) => {
        const uploadFiles = Array.isArray(files) ? files.filter(Boolean) : [];
        const preflight = getMediaUploadPreflight(uploadFiles, setMain);
        const clientKey = String(clientId);
        if (uploadFiles.length === 0) {
            return { queued: false, error: 'Select at least one file first.' };
        }

        if (!preflight.ok) {
            toast.warning(preflight.errors[0] || 'Media upload cannot start with the selected files.');
            return { queued: false, error: preflight.errors[0] || 'Invalid media upload.' };
        }

        if (activeClientUploadsRef.current.has(clientKey)) {
            const message = 'A media upload is already running for this client. Wait for it to finish, then start another batch.';
            toast.warning(message);
            return { queued: false, error: message };
        }

        const uploadId = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
        const items = buildUploadItems(uploadId, uploadFiles);
        const setMainItem = setMain ? items.find((item) => !item.isVideo) : null;
        const entry = {
            id: uploadId,
            clientId: clientKey,
            clientName,
            label: preflight.label,
            items,
            setMain,
            setMainItemId: setMainItem?.id || null,
            status: 'uploading',
            message: 'Uploading in the background',
            attempts: 1,
            createdAt: Date.now(),
        };

        setUploads((current) => [entry, ...current]);
        toast.info(`${preflight.label} uploading in the background.`);
        startUploadRun(uploadId, items, setMainItem?.id || null, clientKey);

        return { queued: true, uploadId };
    }, [buildUploadItems, startUploadRun, toast]);

    const retryUpload = useCallback((uploadId) => {
        const upload = uploads.find((item) => item.id === uploadId);
        const failedItems = upload?.items?.filter((item) => item.status === 'failed') || [];
        if (!upload || upload.status !== 'failed' || failedItems.length === 0) {
            return;
        }

        if (activeClientUploadsRef.current.has(String(upload.clientId))) {
            toast.warning('A media upload is already running for this client. Wait for it to finish, then retry.');
            return;
        }

        const preflight = getMediaUploadPreflight(failedItems.map((item) => item.file), false);
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
                    items: item.items.map((uploadItem) => (
                        uploadItem.status === 'failed'
                            ? { ...uploadItem, status: 'pending', percent: 0, message: 'Waiting to retry' }
                            : uploadItem
                    )),
                }
                : item
        )));
        toast.info(`Retrying ${upload.label}.`);
        const retrySetMainItemId = failedItems.some((item) => item.id === upload.setMainItemId)
            ? upload.setMainItemId
            : null;

        startUploadRun(upload.id, failedItems, retrySetMainItemId, upload.clientId, {
            totalCount: upload.items.length,
            existingSuccessCount: upload.items.filter((item) => item.status === 'success').length,
        });
    }, [startUploadRun, toast, uploads]);

    const [deletions, setDeletions] = useState([]);
    const activeClientDeletionsRef = useRef(new Set());

    const dismissDeletion = useCallback((deletionId) => {
        setDeletions((current) => current.filter((deletion) => (
            deletion.id !== deletionId || deletion.status === 'deleting'
        )));
    }, []);

    const updateDeletionItems = useCallback((deletionId, attachmentIds, patch) => {
        const idSet = new Set(attachmentIds.map((value) => Number(value)));
        setDeletions((current) => current.map((deletion) => (
            deletion.id === deletionId
                ? {
                    ...deletion,
                    items: deletion.items.map((item) => (
                        idSet.has(Number(item.attachmentId)) ? { ...item, ...patch } : item
                    )),
                }
                : deletion
        )));
    }, []);

    const runDeletion = useCallback(async (deletionId, clientId, attachmentIds) => {
        const clientKey = String(clientId);
        const chunks = chunkList(attachmentIds, MEDIA_DELETE_CHUNK_SIZE);
        let deletedCount = 0;
        let failedCount = 0;
        let stopMessage = '';

        try {
            for (let index = 0; index < chunks.length; index += 1) {
                const chunk = chunks[index];

                if (stopMessage) {
                    failedCount += chunk.length;
                    updateDeletionItems(deletionId, chunk, { status: 'failed', message: `Skipped: ${stopMessage}` });
                    continue;
                }

                updateDeletionItems(deletionId, chunk, { status: 'deleting', message: 'Deleting' });
                setDeletions((current) => current.map((deletion) => (
                    deletion.id === deletionId
                        ? {
                            ...deletion,
                            message: `Deleting ${Math.min(attachmentIds.length, (index * MEDIA_DELETE_CHUNK_SIZE) + chunk.length)} of ${attachmentIds.length}`,
                        }
                        : deletion
                )));

                try {
                    const response = await api.post(`/crm/clients/${clientKey}/media/bulk-delete`, {
                        attachment_ids: chunk,
                        resync: index === chunks.length - 1,
                        reason: 'Bulk media delete from client detail',
                    });

                    const results = Array.isArray(response?.data?.results) ? response.data.results : [];
                    chunk.forEach((attachmentId) => {
                        const result = results.find((entry) => Number(entry.attachment_id) === Number(attachmentId));
                        if (!result || result.status === 'deleted') {
                            deletedCount += 1;
                            updateDeletionItems(deletionId, [attachmentId], { status: 'deleted', message: 'Deleted' });
                            return;
                        }

                        failedCount += 1;
                        updateDeletionItems(deletionId, [attachmentId], {
                            status: 'failed',
                            message: result.message || 'Delete failed.',
                        });
                    });
                } catch (error) {
                    const details = deleteErrorDetails(error);
                    failedCount += chunk.length;
                    updateDeletionItems(deletionId, chunk, { status: 'failed', message: details.message });

                    if (details.stopBatch) {
                        stopMessage = details.message;
                    }
                }
            }
        } finally {
            activeClientDeletionsRef.current.delete(clientKey);

            const finalStatus = failedCount > 0 ? 'failed' : 'success';
            const finalMessage = failedCount > 0
                ? `${deletedCount} of ${attachmentIds.length} deleted. ${failedCount} failed.`
                : `${deletedCount} media file${deletedCount === 1 ? '' : 's'} deleted from WordPress.`;

            setDeletions((current) => current.map((deletion) => (
                deletion.id === deletionId
                    ? { ...deletion, status: finalStatus, message: finalMessage }
                    : deletion
            )));

            queryClient.invalidateQueries({ queryKey: ['client', clientKey] });
            queryClient.invalidateQueries({ queryKey: ['client-media', clientKey] });
            queryClient.invalidateQueries({ queryKey: ['clients'] });

            if (finalStatus === 'success') {
                toast.success(finalMessage);
                window.setTimeout(() => dismissDeletion(deletionId), 20000);
            } else {
                toast.warning(finalMessage, { duration: 7000 });
            }
        }
    }, [dismissDeletion, queryClient, toast, updateDeletionItems]);

    const startClientMediaDeletion = useCallback(({ clientId, clientName = '', items }) => {
        const list = (Array.isArray(items) ? items : [])
            .map((item) => ({
                attachmentId: Number(item?.attachmentId ?? item?.id ?? 0),
                name: String(item?.name || '').trim() || `Attachment ${item?.attachmentId ?? item?.id ?? ''}`,
            }))
            .filter((item) => item.attachmentId > 0);

        if (list.length === 0) {
            return { queued: false, error: 'Select at least one media file first.' };
        }

        const clientKey = String(clientId);
        if (activeClientDeletionsRef.current.has(clientKey)) {
            const message = 'A media deletion is already running for this client. Wait for it to finish.';
            toast.warning(message);
            return { queued: false, error: message };
        }

        const deletionId = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
        const entry = {
            id: deletionId,
            clientId: clientKey,
            clientName,
            label: `${list.length} media file${list.length === 1 ? '' : 's'}`,
            items: list.map((item) => ({ ...item, status: 'pending', message: 'Waiting' })),
            status: 'deleting',
            message: 'Deleting in the background',
            createdAt: Date.now(),
        };

        activeClientDeletionsRef.current.add(clientKey);
        setDeletions((current) => [entry, ...current]);
        toast.info(`Deleting ${entry.label} in the background.`);
        void runDeletion(deletionId, clientKey, list.map((item) => item.attachmentId));

        return { queued: true, deletionId };
    }, [runDeletion, toast]);

    const retryDeletion = useCallback((deletionId) => {
        const deletion = deletions.find((entry) => entry.id === deletionId);
        const failedItems = deletion?.items?.filter((item) => item.status === 'failed') || [];
        if (!deletion || deletion.status !== 'failed' || failedItems.length === 0) {
            return;
        }

        const clientKey = String(deletion.clientId);
        if (activeClientDeletionsRef.current.has(clientKey)) {
            toast.warning('A media deletion is already running for this client. Wait for it to finish, then retry.');
            return;
        }

        activeClientDeletionsRef.current.add(clientKey);
        setDeletions((current) => current.map((entry) => (
            entry.id === deletionId
                ? {
                    ...entry,
                    status: 'deleting',
                    message: 'Retrying deletion',
                    items: entry.items.map((item) => (
                        item.status === 'failed' ? { ...item, status: 'pending', message: 'Waiting to retry' } : item
                    )),
                }
                : entry
        )));
        toast.info(`Retrying ${failedItems.length} media deletion${failedItems.length === 1 ? '' : 's'}.`);
        void runDeletion(deletionId, clientKey, failedItems.map((item) => item.attachmentId));
    }, [deletions, runDeletion, toast]);

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
            deletions,
            startClientMediaDeletion,
            retryDeletion,
            dismissDeletion,
            deletionsForClient: (clientId) => deletions.filter((deletion) => deletion.clientId === String(clientId)),
            pendingDeleteIdsForClient: (clientId) => new Set(
                deletions
                    .filter((deletion) => deletion.clientId === String(clientId))
                    .flatMap((deletion) => deletion.items
                        .filter((item) => item.status === 'pending' || item.status === 'deleting' || item.status === 'deleted')
                        .map((item) => Number(item.attachmentId)))
            ),
        };
    }, [
        deletions,
        dismissDeletion,
        dismissUpload,
        retryDeletion,
        retryUpload,
        startClientMediaDeletion,
        startClientMediaUpload,
        uploads,
    ]);

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
