const ROLE_ENV = {
    admin: {
        email: 'PLAYWRIGHT_ADMIN_EMAIL',
        password: 'PLAYWRIGHT_ADMIN_PASSWORD',
    },
    sub_admin: {
        email: 'PLAYWRIGHT_SUB_ADMIN_EMAIL',
        password: 'PLAYWRIGHT_SUB_ADMIN_PASSWORD',
    },
    sales: {
        email: 'PLAYWRIGHT_SALES_EMAIL',
        password: 'PLAYWRIGHT_SALES_PASSWORD',
    },
};

function readEnv(name) {
    const value = process.env[name];
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : '';
}

export function getRoleCredentials(role) {
    const env = ROLE_ENV[role];
    if (!env) {
        throw new Error(`Unsupported browser test role "${role}".`);
    }

    const email = readEnv(env.email);
    const password = readEnv(env.password);

    if (!email || !password) {
        return null;
    }

    return { email, password };
}

export function roleCredentialsAvailable(role) {
    return Boolean(getRoleCredentials(role));
}

export function missingRoleMessage(role) {
    const env = ROLE_ENV[role];
    if (!env) {
        return `Unsupported role "${role}".`;
    }

    return `Set ${env.email} and ${env.password} to run ${role} browser coverage.`;
}

export function getOptionalFixture(name) {
    return readEnv(name);
}
