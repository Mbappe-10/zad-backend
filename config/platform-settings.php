<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform Settings Defaults
    |--------------------------------------------------------------------------
    |
    | القيم الافتراضية لمركز الإعدادات الرئيسي.
    | في حال لم توجد قيمة في قاعدة البيانات سيستخدم النظام هذه القيم.
    |
    */

    'general' => [

        'appNameAr' => 'زاد',
        'appNameEn' => 'ZAD',

        'supportEmail' => 'support@zad.sa',

        'defaultLanguage' => 'ar',

        'timezone' => 'Asia/Riyadh',

        'maintenanceMode' => false,

        'allowRegistration' => true,

    ],

    'registration' => [

        'familyEnabled' => true,
        'familyApprovalRequired' => true,
        'familyApprovalMinutes' => 60,

        'familyNameMin' => 3,
        'familyNameMax' => 40,

        'familyManagerMin' => 3,
        'familyManagerMax' => 40,

        'familyEmailRequired' => true,
        'familyDocumentsRequired' => false,

        'driverEnabled' => true,
        'driverApprovalRequired' => true,
        'driverApprovalMinutes' => 60,

        'driverNameMin' => 3,
        'driverNameMax' => 40,

        'driverPhoneLength' => 10,

        'scooterImagesCount' => 3,

        'scooterRequireIdentity' => true,
        'scooterRequireLicense' => false,

        'motorcycleRequireLicense' => true,
        'carRequireLicense' => true,

        'rejectHtml' => true,
        'rejectScripts' => true,
        'rejectSqlPatterns' => true,

        'trimWhitespace' => true,

        'maximumRequestKb' => 256,

    ],

    'contracts' => [

        'requireFamilyAgreement' => true,
        'requireDriverAgreement' => true,

        'requireOtpAcceptance' => false,

        'forceReacceptOnNewVersion' => true,

        'familyContractVersion' => '1.0',
        'driverContractVersion' => '1.0',

    ],

    'applicationStudio' => [

        'primaryColor' => '#EA7A1A',

        'secondaryColor' => '#17324D',

        'fontFamily' => 'Tajawal',

        'borderRadius' => 16,

        'darkModeEnabled' => true,

        'homeOffersEnabled' => true,
        'homeStoresEnabled' => true,
        'homeCategoriesEnabled' => true,

        'liveStreamingEnabled' => true,

        'couponsEnabled' => true,

    ],

    'dashboardStudio' => [

        'primaryColor' => '#EA7A1A',

        'fontFamily' => 'Tajawal',

        'compactSidebar' => false,

        'allowPageReorder' => true,

        'allowWidgetReorder' => true,

        'showFinanceModule' => true,
        'showHrModule' => true,
        'showAiModule' => true,

        'defaultLandingPage' => '/',

    ],

    'smartDispatch' => [

        'enabled' => true,

        'offerTimeoutSeconds' => 20,

        'maximumAttempts' => 8,

        'distanceWeight' => 35,
        'ratingWeight' => 25,
        'acceptanceWeight' => 20,
        'workloadWeight' => 20,

        'scooterMaxDistanceKm' => 10,
        'motorcycleMaxDistanceKm' => 15,

        'forceCarAfterKm' => 15,

        'recordDecisionReason' => true,

    ],

    'aiControl' => [

        'digitalEmployeesEnabled' => true,

        'externalProvidersEnabled' => true,

        'requireHumanApproval' => true,

        'maximumAutomaticActionValue' => 500,

        'confidenceThreshold' => 85,

        'dailyExecutionLimit' => 1000,

        'encryptProviderSecrets' => true,

        'recordAllAiDecisions' => true,

    ],

    'storage' => [

        'cleanupEnabled' => true,

        'orderProofRetentionHours' => 48,

        'liveStreamRetentionHours' => 2,

        'temporaryUploadRetentionHours' => 2,

        'notificationRetentionDays' => 30,

        'locationRetentionDays' => 7,

        'softDeleteDays' => 30,

        'suspendDeletionOnDispute' => true,

        'compressImages' => true,

        'maximumImageMb' => 5,

        'maximumVideoMb' => 50,

        'orphanCleanupEnabled' => true,

    ],

    'security' => [

        'twoFactorAuthentication' => false,

        'forceTwoFactorForAdmins' => true,

        'sessionTimeoutMinutes' => 120,

        'maximumLoginAttempts' => 5,

        'accountLockMinutes' => 30,

        'minimumPasswordLength' => 8,

        'requireUppercase' => true,

        'requireNumber' => true,

        'requireSpecialCharacter' => true,

        'allowMultipleSessions' => false,

    ],

    'governance' => [

        'enableAuditLogs' => true,

        'preventLogDeletion' => true,

        'requireSensitiveChangeApproval' => true,

        'requireStorageDeletionApproval' => true,

        'enableFourEyesPrinciple' => true,

        'logRetentionDays' => 365,

    ],

];