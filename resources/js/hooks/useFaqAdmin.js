import { useAuth } from './useAuth';

export default function useFaqAdmin() {
    const { user } = useAuth();
    const isAdmin = user?.role === 'admin' || user?.role === 'sub_admin';

    return {
        isAdmin,
        canEdit: isAdmin,
        canManage: isAdmin,
        autosaveDraft: isAdmin,
        publishDraft: isAdmin,
    };
}
