export interface User {
    id: number;
    name: string;
    email: string;
    roles: string[];
}

export interface SharedData {
    auth: {
        user: User | null;
    };
    can: {
        viewDepartments: boolean;
        viewEmployees: boolean;
        viewWorkSchedules: boolean;
    };
    flash: {
        status: string | null;
    };
    [key: string]: unknown;
}
