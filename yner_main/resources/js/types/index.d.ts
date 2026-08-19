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
        viewWorkLocations: boolean;
        viewBiometricDevices: boolean;
        viewDeviceEnrollments: boolean;
        viewAiInsights: boolean;
        viewReportSummaries: boolean;
        viewReports: boolean;
        manageAiSettings: boolean;
        viewRegistrationRequests: boolean;
        viewAccountInvitations: boolean;
        viewMobileCheckIns: boolean;
        checkIn: boolean;
        reviewDeviceResets: boolean;
    };
    flash: {
        status: string | null;
    };
    unreadNotifications: number;
    [key: string]: unknown;
}
