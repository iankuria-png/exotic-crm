// The QueryClient is created above ToastProvider in the tree, so the global
// query/mutation error handler can't call useToast() directly. ToastProvider
// registers its imperative api here on mount; the global handler reads it back.

let toastApi = null;

export function registerToast(api) {
    toastApi = api;
}

export function bridgeToast() {
    return toastApi;
}
