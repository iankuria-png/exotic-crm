export function isForbiddenQueryError(error) {
    return Number(error?.response?.status || 0) === 403;
}
