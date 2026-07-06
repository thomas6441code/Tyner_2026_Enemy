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
        viewPermissionRequests: boolean;
        viewWorkSchedules: boolean;
        viewBiometricDevices: boolean;
        viewDeviceEnrollments: boolean;
        viewAiInsights: boolean;
        viewReportSummaries: boolean;
        viewReports: boolean;
        manageAiSettings: boolean;
    };
    flash: {
        status: string | null;
    };
    unreadNotifications: number;
    [key: string]: unknown;
}
