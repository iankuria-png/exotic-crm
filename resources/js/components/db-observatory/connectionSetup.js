export function connectionTlsDefault(host) {
    return ['localhost', '127.0.0.1', '::1', ''].includes(String(host || '').trim().toLowerCase()) ? 'none' : 'verify';
}

// Only newly generated dedicated-reader credentials reach this helper.
export function readerSetup(database) {
    const account = /^([a-zA-Z0-9]+)_/.exec(database || '')?.[1];
    return account ? { account, username: `${account}_dbscan` } : null;
}

export function generateReaderPassword() {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    const bytes = new Uint8Array(48);
    const chars = [];
    while (chars.length < 32) {
        crypto.getRandomValues(bytes);
        for (const byte of bytes) {
            // Rejection sampling avoids modulo bias.
            if (byte < Math.floor(256 / alphabet.length) * alphabet.length) chars.push(alphabet[byte % alphabet.length]);
            if (chars.length === 32) break;
        }
    }
    const password = chars.join('');
    return /[A-Z]/.test(password) && /[a-z]/.test(password) && /[0-9]/.test(password) ? password : generateReaderPassword();
}

const quote = (value) => "'" + String(value).replaceAll("'", "'\\''") + "'";
export function readerCommand(database, username, password) {
    if (!/^[a-zA-Z0-9_]+$/.test(database) || !/^[a-zA-Z0-9_]+$/.test(username) || !/^[a-zA-Z0-9]{32}$/.test(password)) return '';
    return `uapi --output=jsonpretty Mysql create_user name=${quote(username)} password=${quote(password)}\n` +
        `uapi --output=jsonpretty Mysql set_privileges_on_database user=${quote(username)} database=${quote(database)} privileges=SELECT`;
}
