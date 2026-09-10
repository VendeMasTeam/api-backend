<?php

use App\Http\Controllers\Api\AccessControlController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AdminBillingController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminTelemetryController;
use App\Http\Controllers\Api\AdminTenantController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\CompetitiveIntelligenceController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\CrmEntityController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\CustomFieldController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentAlertController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentTypeController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FinancialOperationsController;
use App\Http\Controllers\Api\InteractionController;
use App\Http\Controllers\Api\InventoryCategoryController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InventoryProductController;
use App\Http\Controllers\Api\InventoryWarehouseController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\OpportunityController;
use App\Http\Controllers\Api\PartnerController;
use App\Http\Controllers\Api\PartnerResourceController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PriceBookController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\RelationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\AdminPlatformRoleController;
use App\Http\Controllers\Api\AdminPlatformUserController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SegmentController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StaticOptionController;
use App\Http\Controllers\Api\SupportSessionController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TenantOptionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

/*
|--------------------------------------------------------------------------
| Security Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'tenant.active', 'tenant.token', 'tenant.schema'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/2fa/setup', [AuthController::class, 'twoFactorSetup']);
    Route::post('/2fa/confirm', [AuthController::class, 'confirmTwoFactor']);
});

/*
|--------------------------------------------------------------------------
| Full Access Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'tenant.active', 'tenant.token', 'tenant.schema', 'full.access', 'support.readonly'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/me/features', [AuthController::class, 'features']);
    Route::put('/me', [AuthController::class, 'updateMe']);
    Route::get('/auth/init', [AuthController::class, 'init']);
    Route::get('/support-session/current', [SupportSessionController::class, 'current']);
    Route::post('/support-session/stop', [SupportSessionController::class, 'stop']);
    Route::post('/2fa/recovery-codes/regenerate', [AuthController::class, 'regenerateRecoveryCodes']);
    Route::delete('/2fa', [AuthController::class, 'disableTwoFactor']);

    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.manage');
    Route::get('/users/statuses', [StaticOptionController::class, 'userStatuses'])->middleware('permission:users.manage');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.manage');
    Route::get('/users/{uid}', [UserController::class, 'show'])->middleware('permission:users.manage');
    Route::put('/users/{uid}', [UserController::class, 'update'])->middleware('permission:users.manage');
    Route::post('/users/{uid}/2fa/reset', [UserController::class, 'resetTwoFactor'])->middleware('permission:users.manage');
    Route::delete('/users/{uid}', [UserController::class, 'destroy'])->middleware('permission:users.manage');
    Route::get('/rbac/roles', [AccessControlController::class, 'roles'])->middleware('permission:users.manage');
    Route::post('/rbac/roles', [AccessControlController::class, 'storeRole'])->middleware('permission:users.manage');
    Route::put('/rbac/roles/{roleUid}', [AccessControlController::class, 'updateRole'])->middleware('permission:users.manage');
    Route::delete('/rbac/roles/{roleUid}', [AccessControlController::class, 'destroyRole'])->middleware('permission:users.manage');
    Route::get('/rbac/permissions', [AccessControlController::class, 'permissions'])->middleware('permission:users.manage');
    Route::get('/users/{uid}/access', [AccessControlController::class, 'userAccess'])->middleware('permission:users.manage');
    Route::post('/users/{uid}/manager', [AccessControlController::class, 'assignManager'])->middleware('permission:users.manage');
    Route::post('/users/{uid}/roles', [AccessControlController::class, 'assignRole'])->middleware('permission:users.manage');
    Route::delete('/users/{uid}/roles/{roleUid}', [AccessControlController::class, 'removeRole'])->middleware('permission:users.manage');
    Route::post('/users/{uid}/permissions', [AccessControlController::class, 'grantPermission'])->middleware('permission:users.manage');
    Route::delete('/users/{uid}/permissions/{permissionUid}', [AccessControlController::class, 'revokePermission'])->middleware('permission:users.manage');

    Route::prefix('plans')->group(function () {
        Route::get('/', [PlanController::class, 'index'])->middleware('permission:plans.manage');
        Route::post('/', [PlanController::class, 'store'])->middleware('permission:plans.manage');
        Route::put('/{uid}', [PlanController::class, 'update'])->middleware('permission:plans.manage');
        Route::delete('/{uid}', [PlanController::class, 'destroy'])->middleware('permission:plans.manage');
    });

    Route::prefix('accounts')->group(function () {
        Route::get('/', [AccountController::class, 'index'])->middleware('permission:accounts.read');
        Route::get('/{uid}', [AccountController::class, 'show'])->middleware('permission:accounts.read');
        Route::get('/{uid}/products', [ProductController::class, 'accountProducts'])->middleware('permission:products.read');
        Route::post('/', [AccountController::class, 'store'])->middleware('permission:accounts.create');
        Route::post('/{uid}/products', [ProductController::class, 'storeAccountProduct'])->middleware('permission:products.install');
        Route::put('/{uid}', [AccountController::class, 'update'])->middleware('permission:accounts.update');
        Route::post('/{uid}/owner', [AccountController::class, 'assignOwner'])->middleware('permission:accounts.update');
        Route::delete('/{uid}', [AccountController::class, 'destroy'])->middleware('permission:accounts.delete');
    });

    Route::prefix('contacts')->group(function () {
        Route::get('/', [ContactController::class, 'index'])->middleware('permission:contacts.read');
        Route::post('/export', [ContactController::class, 'export'])->middleware('permission:contacts.read');
        Route::post('/check-duplicate', [ContactController::class, 'checkDuplicate'])->middleware('permission:contacts.read');
        Route::get('/{uid}', [ContactController::class, 'show'])->middleware('permission:contacts.read');
        Route::post('/', [ContactController::class, 'store'])->middleware('permission:contacts.create');
        Route::put('/{uid}', [ContactController::class, 'update'])->middleware('permission:contacts.update');
        Route::post('/{uid}/owner', [ContactController::class, 'assignOwner'])->middleware('permission:contacts.update');
        Route::delete('/{uid}', [ContactController::class, 'destroy'])->middleware('permission:contacts.delete');
    });

    Route::prefix('relations')->group(function () {
        Route::get('/', [RelationController::class, 'index'])->middleware('permission:relations.read');
        Route::get('/with-entities', [RelationController::class, 'indexWithEntities'])->middleware('permission:relations.read');
        Route::get('/hierarchy/{type}/{uid}', [RelationController::class, 'hierarchy'])->middleware('permission:relations.read');
        Route::post('/', [RelationController::class, 'store'])->middleware('permission:relations.create');
        Route::post('/remove', [RelationController::class, 'destroyByPair'])->middleware('permission:relations.delete');
        Route::delete('/', [RelationController::class, 'destroyByPair'])->middleware('permission:relations.delete');
        Route::get('/{type}/{uid}', [RelationController::class, 'showByEntity'])->middleware('permission:relations.read');
        Route::delete('/{uid}', [RelationController::class, 'destroy'])->middleware('permission:relations.delete');
    });

    Route::prefix('crm-entities')->group(function () {
        Route::get('/', [CrmEntityController::class, 'index'])->middleware('permission:crm-entities.read');
        Route::post('/', [CrmEntityController::class, 'store'])->middleware('permission:crm-entities.create');
        Route::post('/{uid}/owner', [CrmEntityController::class, 'assignOwner'])->middleware('permission:crm-entities.update');
    });

    Route::prefix('tags')->group(function () {
        Route::get('/', [TagController::class, 'index'])->middleware('permission:tags.manage');
        Route::post('/', [TagController::class, 'store'])->middleware('permission:tags.manage');
        Route::put('/{uid}', [TagController::class, 'update'])->middleware('permission:tags.manage');
        Route::delete('/{uid}', [TagController::class, 'destroy'])->middleware('permission:tags.manage');
        Route::post('/assign', [TagController::class, 'assign'])->middleware('permission:tags.manage');
        Route::post('/unassign', [TagController::class, 'unassign'])->middleware('permission:tags.manage');
        Route::delete('/assign', [TagController::class, 'unassign'])->middleware('permission:tags.manage');
    });

    Route::prefix('tasks')->group(function () {
        Route::get('/statuses', [StaticOptionController::class, 'taskStatuses'])->middleware('permission:tasks.read');
        Route::get('/priorities', [StaticOptionController::class, 'taskPriorities'])->middleware('permission:tasks.read');
        Route::get('/', [TaskController::class, 'index'])->middleware('permission:tasks.read');
        Route::get('/{uid}', [TaskController::class, 'show'])->middleware('permission:tasks.read');
        Route::post('/', [TaskController::class, 'store'])->middleware('permission:tasks.create');
        Route::put('/{uid}', [TaskController::class, 'update'])->middleware('permission:tasks.update');
        Route::delete('/{uid}', [TaskController::class, 'destroy'])->middleware('permission:tasks.delete');
    });

    Route::prefix('interactions')->group(function () {
        Route::get('/', [InteractionController::class, 'index'])->middleware('permission:interactions.read');
        Route::get('/{type}/{uid}', [InteractionController::class, 'timeline'])->middleware('permission:interactions.read');
        Route::post('/notes', [InteractionController::class, 'note'])->middleware('permission:interactions.create');
        Route::post('/calls', [InteractionController::class, 'call'])->middleware('permission:interactions.create');
        Route::post('/emails', [InteractionController::class, 'email'])->middleware('permission:interactions.create');
    });

    Route::prefix('activities')->group(function () {
        Route::get('/', [ActivityController::class, 'index'])->middleware('permission:activities.read');
        Route::get('/range', [ActivityController::class, 'byRange'])->middleware('permission:activities.read');
        Route::get('/{uid}', [ActivityController::class, 'show'])->middleware('permission:activities.read');
        Route::post('/', [ActivityController::class, 'store'])->middleware('permission:activities.create');
        Route::put('/{uid}', [ActivityController::class, 'update'])->middleware('permission:activities.update');
        Route::delete('/{uid}', [ActivityController::class, 'destroy'])->middleware('permission:activities.delete');
    });

    Route::prefix('segments')->group(function () {
        Route::get('/', [SegmentController::class, 'index'])->middleware('permission:segments.read');
        Route::post('/', [SegmentController::class, 'store'])->middleware('permission:segments.manage');
        Route::get('/{uid}', [SegmentController::class, 'show'])->middleware('permission:segments.read');
        Route::put('/{uid}', [SegmentController::class, 'update'])->middleware('permission:segments.manage');
        Route::delete('/{uid}', [SegmentController::class, 'destroy'])->middleware('permission:segments.manage');
        Route::post('/{uid}/run', [SegmentController::class, 'run'])->middleware('permission:segments.read');
    });

    Route::prefix('teams')->group(function () {
        Route::get('/', [TeamController::class, 'index'])->middleware('permission:teams.read');
        Route::post('/', [TeamController::class, 'store'])->middleware('permission:teams.manage');
        Route::get('/{uid}', [TeamController::class, 'show'])->middleware('permission:teams.read');
        Route::put('/{uid}', [TeamController::class, 'update'])->middleware('permission:teams.manage');
        Route::delete('/{uid}', [TeamController::class, 'destroy'])->middleware('permission:teams.manage');
        Route::post('/{uid}/members', [TeamController::class, 'addMember'])->middleware('permission:teams.manage');
        Route::delete('/{uid}/members/{userUid}', [TeamController::class, 'removeMember'])->middleware('permission:teams.manage');
    });

    Route::prefix('automation')->group(function () {
        Route::get('/trigger-events', [AutomationController::class, 'triggerEvents'])->middleware('permission:automation.read');
        Route::get('/actions', [AutomationController::class, 'actions'])->middleware('permission:automation.read');
        Route::get('/rules', [AutomationController::class, 'rules'])->middleware('permission:automation.read');
        Route::get('/rules/{uid}', [AutomationController::class, 'showRule'])->middleware('permission:automation.read');
        Route::post('/rules', [AutomationController::class, 'storeRule'])->middleware('permission:automation.create');
        Route::put('/rules/{uid}', [AutomationController::class, 'updateRule'])->middleware('permission:automation.update');
        Route::delete('/rules/{uid}', [AutomationController::class, 'destroyRule'])->middleware('permission:automation.delete');
        Route::post('/rules/{uid}/toggle', [AutomationController::class, 'toggleRule'])->middleware('permission:automation.update');
        Route::get('/assignment-rules', [AutomationController::class, 'assignmentRules'])->middleware('permission:automation.read');
        Route::post('/assignment-rules', [AutomationController::class, 'storeAssignmentRule'])->middleware('permission:automation.create');
        Route::put('/assignment-rules/{uid}', [AutomationController::class, 'updateAssignmentRule'])->middleware('permission:automation.update');
        Route::delete('/assignment-rules/{uid}', [AutomationController::class, 'destroyAssignmentRule'])->middleware('permission:automation.delete');
    });

    Route::prefix('documents')->group(function () {
        Route::get('/', [DocumentController::class, 'list'])->middleware('permission:documents.read');
        Route::get('/entity/{type}/{uid}', [DocumentController::class, 'index'])->middleware('permission:documents.read');
        Route::get('/account/{accountUid}', [DocumentController::class, 'accountDocuments'])->middleware('permission:documents.read');
        Route::get('/missing/{accountUid}', [DocumentController::class, 'missingDocuments'])->middleware('permission:documents.read');
        Route::get('/download/{uid}', [DocumentController::class, 'download'])->middleware('permission:documents.read');
        Route::get('/{uid}/versions', [DocumentController::class, 'versions'])->middleware('permission:documents.read');
        Route::get('/{uid}', [DocumentController::class, 'show'])->middleware('permission:documents.read');
        Route::post('/', [DocumentController::class, 'upload'])->middleware('permission:documents.create');
        Route::put('/{uid}', [DocumentController::class, 'update'])->middleware('permission:documents.manage');
        Route::delete('/{uid}', [DocumentController::class, 'destroy'])->middleware('permission:documents.manage');
    });

    Route::prefix('document-types')->group(function () {
        Route::get('/', [DocumentTypeController::class, 'index'])->middleware('permission:documents.read');
        Route::post('/', [DocumentTypeController::class, 'store'])->middleware('permission:documents.manage');
        Route::put('/{uid}', [DocumentTypeController::class, 'update'])->middleware('permission:documents.manage');
        Route::delete('/{uid}', [DocumentTypeController::class, 'destroy'])->middleware('permission:documents.manage');
    });

    Route::prefix('document-alerts')->group(function () {
        Route::get('/', [DocumentAlertController::class, 'index'])->middleware('permission:documents.read');
        Route::post('/generate', [DocumentAlertController::class, 'generate'])->middleware('permission:documents.manage');
        Route::post('/{uid}/read', [DocumentAlertController::class, 'markAsRead'])->middleware('permission:documents.manage');
    });

    Route::prefix('inventory')->middleware('feature:inventory')->group(function () {
        Route::get('/master', [InventoryController::class, 'master'])->middleware('permission:inventory.read');
        Route::get('/availability', [InventoryController::class, 'availability'])->middleware('permission:inventory.read');
        Route::get('/movements/summary', [InventoryController::class, 'movementsSummary'])->middleware('permission:inventory.read');
        Route::get('/movements', [InventoryController::class, 'movements'])->middleware('permission:inventory.read');
        Route::post('/stocks/adjust/bulk', [InventoryController::class, 'adjustBulk'])->middleware('permission:inventory.manage');
        Route::post('/stocks/adjust', [InventoryController::class, 'adjust'])->middleware('permission:inventory.manage');
        Route::post('/reservations', [InventoryController::class, 'reserve'])->middleware('permission:inventory.reserve');
        Route::get('/reservations/source/{sourceType}/{sourceUid}', [InventoryController::class, 'reservationsBySource'])->middleware('permission:inventory.read');
        Route::delete('/reservations/{uid}', [InventoryController::class, 'releaseReservation'])->middleware('permission:inventory.reserve');
        Route::post('/reservations/{uid}/consume', [InventoryController::class, 'consumeReservation'])->middleware('permission:inventory.reserve');
        Route::post('/movements/transfer', [InventoryController::class, 'transfer'])->middleware('permission:inventory.manage');
        Route::get('/report', [InventoryController::class, 'report'])->middleware('permission:inventory.report');
        Route::get('/report/export', [InventoryController::class, 'exportReport'])->middleware('permission:inventory.report');
        Route::post('/stock/export', [InventoryController::class, 'exportStock'])->middleware('permission:inventory.read');

        Route::prefix('categories')->group(function () {
            Route::get('/', [InventoryCategoryController::class, 'index'])->middleware('permission:inventory.read');
            Route::post('/', [InventoryCategoryController::class, 'store'])->middleware('permission:inventory.manage');
            Route::put('/{uid}', [InventoryCategoryController::class, 'update'])->middleware('permission:inventory.manage');
            Route::delete('/{uid}', [InventoryCategoryController::class, 'destroy'])->middleware('permission:inventory.manage');
        });

        Route::prefix('products')->group(function () {
            Route::get('/', [InventoryProductController::class, 'index'])->middleware('permission:inventory.read');
            Route::post('/export', [InventoryProductController::class, 'export'])->middleware('permission:inventory.read');
            Route::post('/', [InventoryProductController::class, 'store'])->middleware('permission:inventory.manage');
            Route::put('/{uid}', [InventoryProductController::class, 'update'])->middleware('permission:inventory.manage');
            Route::delete('/{uid}', [InventoryProductController::class, 'destroy'])->middleware('permission:inventory.manage');
        });

        Route::prefix('warehouses')->group(function () {
            Route::get('/', [InventoryWarehouseController::class, 'index'])->middleware('permission:inventory.read');
            Route::get('/{uid}/stocks', [InventoryWarehouseController::class, 'stocks'])->middleware('permission:inventory.read');
            Route::post('/', [InventoryWarehouseController::class, 'store'])->middleware('permission:inventory.manage');
            Route::put('/{uid}', [InventoryWarehouseController::class, 'update'])->middleware('permission:inventory.manage');
            Route::delete('/{uid}', [InventoryWarehouseController::class, 'destroy'])->middleware('permission:inventory.manage');
        });
    });

    Route::prefix('quotations')->group(function () {
        Route::get('/statuses', [StaticOptionController::class, 'quotationStatuses'])->middleware('permission:quotations.read');
        Route::get('/', [QuotationController::class, 'index'])->middleware('permission:quotations.read');
        Route::get('/{uid}', [QuotationController::class, 'show'])->middleware('permission:quotations.read');
        Route::get('/{uid}/pdf', [QuotationController::class, 'downloadPdf'])->middleware('permission:quotations.read');
        Route::post('/', [QuotationController::class, 'store'])->middleware('permission:quotations.create');
        Route::put('/{uid}', [QuotationController::class, 'update'])->middleware('permission:quotations.update');
        Route::post('/{uid}/send', [QuotationController::class, 'sendPdf'])->middleware('permission:quotations.update');
        Route::post('/{uid}/items', [QuotationController::class, 'addItem'])->middleware('permission:quotations.update');
        Route::put('/items/{itemUid}', [QuotationController::class, 'updateItem'])->middleware('permission:quotations.update');
        Route::delete('/items/{itemUid}', [QuotationController::class, 'destroyItem'])->middleware('permission:quotations.update');
        Route::post('/items/{itemUid}/reserve-stock', [QuotationController::class, 'reserveItemStock'])->middleware('permission:inventory.reserve');
        Route::delete('/items/{itemUid}/reservations/{reservationUid}', [QuotationController::class, 'releaseItemReservation'])->middleware('permission:inventory.reserve');
    });

    Route::prefix('quotes')->group(function () {
        Route::get('/', [QuoteController::class, 'index'])->middleware('permission:quotations.read');
        Route::post('/', [QuoteController::class, 'store'])->middleware('permission:quotations.create');
        Route::post('/{uid}/items', [QuoteController::class, 'addItem'])->middleware('permission:quotations.update');
        Route::post('/{uid}/approve', [QuoteController::class, 'approve'])->middleware('permission:quotations.update');
        Route::post('/{uid}/reject', [QuoteController::class, 'reject'])->middleware('permission:quotations.update');
        Route::post('/{uid}/convert', [QuoteController::class, 'convert'])->middleware('permission:finance.manage');
    });

    Route::prefix('price-books')->group(function () {
        Route::get('/', [PriceBookController::class, 'index'])->middleware('permission:price-books.read');
        Route::get('/{uid}', [PriceBookController::class, 'show'])->middleware('permission:price-books.read');
        Route::post('/', [PriceBookController::class, 'store'])->middleware('permission:price-books.manage');
        Route::put('/{uid}', [PriceBookController::class, 'update'])->middleware('permission:price-books.manage');
        Route::delete('/{uid}', [PriceBookController::class, 'destroy'])->middleware('permission:price-books.manage');
    });

    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index'])->middleware('permission:products.read');
        Route::get('/{uid}', [ProductController::class, 'show'])->middleware('permission:products.read');
        Route::get('/{uid}/versions', [ProductController::class, 'versions'])->middleware('permission:products.read');
        Route::get('/{uid}/dependencies', [ProductController::class, 'dependencies'])->middleware('permission:products.read');
        Route::post('/', [ProductController::class, 'store'])->middleware('permission:products.manage');
        Route::post('/{uid}/versions', [ProductController::class, 'storeVersion'])->middleware('permission:products.manage');
        Route::put('/versions/{versionUid}', [ProductController::class, 'updateVersion'])->middleware('permission:products.manage');
        Route::post('/{uid}/dependencies', [ProductController::class, 'storeDependency'])->middleware('permission:products.manage');
        Route::delete('/dependencies/{dependencyUid}', [ProductController::class, 'destroyDependency'])->middleware('permission:products.manage');
        Route::put('/{uid}', [ProductController::class, 'update'])->middleware('permission:products.manage');
        Route::delete('/{uid}', [ProductController::class, 'destroy'])->middleware('permission:products.manage');
    });

    Route::prefix('commissions')->group(function () {
        Route::get('/plans', [CommissionController::class, 'plans'])->middleware('permission:commissions.read');
        Route::post('/plans', [CommissionController::class, 'storePlan'])->middleware('permission:commissions.manage');
        Route::get('/plans/{uid}', [CommissionController::class, 'showPlan'])->middleware('permission:commissions.read');
        Route::put('/plans/{uid}', [CommissionController::class, 'updatePlan'])->middleware('permission:commissions.manage');
        Route::delete('/plans/{uid}', [CommissionController::class, 'destroyPlan'])->middleware('permission:commissions.manage');
        Route::get('/assignments', [CommissionController::class, 'assignments'])->middleware('permission:commissions.read');
        Route::post('/assignments', [CommissionController::class, 'storeAssignment'])->middleware('permission:commissions.manage');
        Route::post('/assignments/bulk', [CommissionController::class, 'storeBulkAssignments'])->middleware('permission:commissions.manage');
        Route::get('/assignments/{uid}', [CommissionController::class, 'showAssignment'])->middleware('permission:commissions.read');
        Route::put('/assignments/{uid}', [CommissionController::class, 'updateAssignment'])->middleware('permission:commissions.manage');
        Route::delete('/assignments/{uid}', [CommissionController::class, 'destroyAssignment'])->middleware('permission:commissions.manage');
        Route::get('/targets', [CommissionController::class, 'targets'])->middleware('permission:commissions.read');
        Route::post('/targets', [CommissionController::class, 'storeTarget'])->middleware('permission:commissions.manage');
        Route::get('/targets/{uid}', [CommissionController::class, 'showTarget'])->middleware('permission:commissions.read');
        Route::put('/targets/{uid}', [CommissionController::class, 'updateTarget'])->middleware('permission:commissions.manage');
        Route::delete('/targets/{uid}', [CommissionController::class, 'destroyTarget'])->middleware('permission:commissions.manage');
        Route::get('/rules', [CommissionController::class, 'rules'])->middleware('permission:commissions.read');
        Route::post('/rules', [CommissionController::class, 'storeRule'])->middleware('permission:commissions.manage');
        Route::put('/rules/{uid}', [CommissionController::class, 'updateRule'])->middleware('permission:commissions.manage');
        Route::delete('/rules/{uid}', [CommissionController::class, 'destroyRule'])->middleware('permission:commissions.manage');
        Route::post('/financial-records', [CommissionController::class, 'recordFinancialEvent'])->middleware('permission:commissions.manage');
        Route::get('/entries', [CommissionController::class, 'entries'])->middleware('permission:commissions.read');
        Route::put('/entries/{uid}/pay', [CommissionController::class, 'payEntry'])->middleware('permission:commissions.manage');
        Route::get('/my-summary', [CommissionController::class, 'mySummary'])->middleware('permission:commissions.read');
        Route::get('/dashboard/{userUid}', [CommissionController::class, 'dashboard'])->middleware('permission:commissions.read');
        Route::post('/simulate', [CommissionController::class, 'simulate'])->middleware('permission:commissions.read');
        Route::get('/history/pdf', [CommissionController::class, 'historyPdf'])->middleware('permission:commissions.read');
        Route::get('/periods', [CommissionController::class, 'periods'])->middleware('permission:commissions.read');
        Route::get('/runs', [CommissionController::class, 'runs'])->middleware('permission:commissions.read');
        Route::post('/runs', [CommissionController::class, 'storeRun'])->middleware('permission:commissions.manage');
        Route::post('/runs/{uid}/approve', [CommissionController::class, 'approveRun'])->middleware('permission:commissions.manage');
        Route::post('/runs/{uid}/pay', [CommissionController::class, 'payRun'])->middleware('permission:commissions.manage');
    });

    Route::prefix('competitive-intelligence')->group(function () {
        Route::get('/competitors', [CompetitiveIntelligenceController::class, 'competitors'])->middleware('permission:competitive-intelligence.read');
        Route::post('/competitors', [CompetitiveIntelligenceController::class, 'storeCompetitor'])->middleware('permission:competitive-intelligence.manage');
        Route::put('/competitors/{uid}', [CompetitiveIntelligenceController::class, 'updateCompetitor'])->middleware('permission:competitive-intelligence.manage');
        Route::delete('/competitors/{uid}', [CompetitiveIntelligenceController::class, 'destroyCompetitor'])->middleware('permission:competitive-intelligence.manage');

        Route::get('/battlecards', [CompetitiveIntelligenceController::class, 'battlecards'])->middleware('permission:competitive-intelligence.read');
        Route::get('/competitors/{uid}/battlecards', [CompetitiveIntelligenceController::class, 'battlecardsByCompetitor'])->middleware('permission:competitive-intelligence.read');
        Route::post('/battlecards', [CompetitiveIntelligenceController::class, 'storeBattlecard'])->middleware('permission:competitive-intelligence.manage');
        Route::put('/battlecards/{uid}', [CompetitiveIntelligenceController::class, 'updateBattlecard'])->middleware('permission:competitive-intelligence.manage');
        Route::delete('/battlecards/{uid}', [CompetitiveIntelligenceController::class, 'destroyBattlecard'])->middleware('permission:competitive-intelligence.manage');

        Route::get('/heatmap', [CompetitiveIntelligenceController::class, 'heatmap'])->middleware('permission:competitive-intelligence.report');
        Route::get('/lost-reasons', [CompetitiveIntelligenceController::class, 'lostReasons'])->middleware('permission:competitive-intelligence.read');
        Route::post('/lost-reasons', [CompetitiveIntelligenceController::class, 'storeLostReason'])->middleware('permission:competitive-intelligence.manage');
        Route::put('/lost-reasons/{uid}', [CompetitiveIntelligenceController::class, 'updateLostReason'])->middleware('permission:competitive-intelligence.manage');
        Route::delete('/lost-reasons/{uid}', [CompetitiveIntelligenceController::class, 'destroyLostReason'])->middleware('permission:competitive-intelligence.manage');
        Route::get('/lost-reasons/report', [CompetitiveIntelligenceController::class, 'lostReasonsReport'])->middleware('permission:competitive-intelligence.report');
    });

    Route::get('/intelligence/heatmap', [CompetitiveIntelligenceController::class, 'heatmap'])->middleware('permission:competitive-intelligence.report');
    Route::get('/battlecards', [CompetitiveIntelligenceController::class, 'battlecards'])->middleware('permission:competitive-intelligence.read');
    Route::get('/lost-reasons/report', [CompetitiveIntelligenceController::class, 'lostReasonsReport'])->middleware('permission:competitive-intelligence.report');
    Route::get('/lost-reasons', [CompetitiveIntelligenceController::class, 'lostReasons'])->middleware('permission:competitive-intelligence.read');

    Route::prefix('expenses')->group(function () {
        Route::get('/categories', [ExpenseController::class, 'categories'])->middleware('permission:expenses.read');
        Route::post('/categories', [ExpenseController::class, 'storeCategory'])->middleware('permission:expenses.manage');
        Route::put('/categories/{uid}', [ExpenseController::class, 'updateCategory'])->middleware('permission:expenses.manage');
        Route::delete('/categories/{uid}', [ExpenseController::class, 'destroyCategory'])->middleware('permission:expenses.manage');
        Route::get('/suppliers', [ExpenseController::class, 'suppliers'])->middleware('permission:expenses.read');
        Route::post('/suppliers', [ExpenseController::class, 'storeSupplier'])->middleware('permission:expenses.manage');
        Route::put('/suppliers/{uid}', [ExpenseController::class, 'updateSupplier'])->middleware('permission:expenses.manage');
        Route::delete('/suppliers/{uid}', [ExpenseController::class, 'destroySupplier'])->middleware('permission:expenses.manage');
        Route::get('/cost-centers', [ExpenseController::class, 'costCenters'])->middleware('permission:expenses.read');
        Route::post('/cost-centers', [ExpenseController::class, 'storeCostCenter'])->middleware('permission:expenses.manage');
        Route::put('/cost-centers/{uid}', [ExpenseController::class, 'updateCostCenter'])->middleware('permission:expenses.manage');
        Route::delete('/cost-centers/{uid}', [ExpenseController::class, 'destroyCostCenter'])->middleware('permission:expenses.manage');
        Route::get('/report', [ExpenseController::class, 'report'])->middleware('permission:expenses.report');
        Route::get('/profitability', [ExpenseController::class, 'profitability'])->middleware('permission:expenses.report');
        Route::get('/', [ExpenseController::class, 'index'])->middleware('permission:expenses.read');
        Route::post('/', [ExpenseController::class, 'store'])->middleware('permission:expenses.manage');
        Route::put('/{uid}', [ExpenseController::class, 'update'])->middleware('permission:expenses.manage');
        Route::delete('/{uid}', [ExpenseController::class, 'destroy'])->middleware('permission:expenses.manage');
    });

    Route::prefix('purchases')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index'])->middleware('permission:purchases.read');
        Route::post('/', [PurchaseOrderController::class, 'store'])->middleware('permission:purchases.manage');
        Route::get('/payables', [PurchaseOrderController::class, 'payables'])->middleware('permission:purchases.read');
        Route::get('/orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchases.read');
        Route::post('/orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchases.manage');
        Route::get('/orders/{uid}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchases.read');
        Route::get('/orders/{uid}/receipts', [PurchaseOrderController::class, 'receipts'])->middleware('permission:purchases.read');
        Route::put('/{uid}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchases.manage');
        Route::put('/orders/{uid}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchases.manage');
        Route::post('/orders/{uid}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:purchases.manage');
        Route::post('/orders/{uid}/receive-partial', [PurchaseOrderController::class, 'receivePartial'])->middleware('permission:purchases.manage');
        Route::post('/orders/{uid}/receive', [PurchaseOrderController::class, 'markReceived'])->middleware('permission:purchases.manage');
        Route::post('/orders/{uid}/payments', [PurchaseOrderController::class, 'registerPayment'])->middleware('permission:purchases.manage');
    });

    Route::prefix('opportunities')->group(function () {
        Route::get('/stages', [OpportunityController::class, 'stages'])->middleware('permission:opportunities.read');
        Route::post('/stages', [OpportunityController::class, 'storeStage'])->middleware('permission:opportunities.manage');
        Route::put('/stages/{uid}', [OpportunityController::class, 'updateStage'])->middleware('permission:opportunities.manage');
        Route::delete('/stages/{uid}', [OpportunityController::class, 'destroyStage'])->middleware('permission:opportunities.manage');
        Route::get('/board', [OpportunityController::class, 'board'])->middleware('permission:opportunities.read');
        Route::get('/summary', [OpportunityController::class, 'summary'])->middleware('permission:opportunities.read');
        Route::get('/template', [OpportunityController::class, 'template'])->middleware('permission:opportunities.read');
        Route::post('/import', [OpportunityController::class, 'import'])->middleware('permission:opportunities.manage');
        Route::get('/', [OpportunityController::class, 'index'])->middleware('permission:opportunities.read');
        Route::post('/', [OpportunityController::class, 'store'])->middleware('permission:opportunities.manage');
        Route::get('/{uid}', [OpportunityController::class, 'show'])->middleware('permission:opportunities.read');
        Route::post('/{uid}/won', [OpportunityController::class, 'markWon'])->middleware('permission:opportunities.manage');
        Route::post('/{uid}/lost', [OpportunityController::class, 'markLost'])->middleware('permission:opportunities.manage');
        Route::get('/{uid}/tasks', [OpportunityController::class, 'tasks'])->middleware('permission:tasks.read');
        Route::post('/{uid}/tasks', [OpportunityController::class, 'storeTask'])->middleware('permission:tasks.create');
        Route::get('/{uid}/activities', [OpportunityController::class, 'activities'])->middleware('permission:activities.read');
        Route::post('/{uid}/activities', [OpportunityController::class, 'storeActivity'])->middleware('permission:activities.create');
        Route::put('/{uid}/activities/{activityUid}', [OpportunityController::class, 'updateActivity'])->middleware('permission:activities.update');
        Route::delete('/{uid}/activities/{activityUid}', [OpportunityController::class, 'destroyActivity'])->middleware('permission:activities.delete');
        Route::put('/{uid}', [OpportunityController::class, 'update'])->middleware('permission:opportunities.manage');
        Route::delete('/{uid}', [OpportunityController::class, 'destroy'])->middleware('permission:opportunities.manage');
    });

    Route::prefix('partners')->group(function () {
        Route::get('/types', [PartnerController::class, 'types'])->middleware('permission:partners.read');
        Route::get('/', [PartnerController::class, 'index'])->middleware('permission:partners.read');
        Route::post('/', [PartnerController::class, 'store'])->middleware('permission:partners.manage');
        Route::put('/{uid}', [PartnerController::class, 'update'])->middleware('permission:partners.manage');
        Route::get('/materials', [PartnerResourceController::class, 'index'])->middleware('permission:partners.resources.read');
        Route::post('/materials', [PartnerResourceController::class, 'store'])->middleware('permission:partners.resources.manage');

        Route::prefix('opportunities')->group(function () {
            Route::get('/statuses', [PartnerController::class, 'opportunityStatuses'])->middleware('permission:partners.opportunities.read');
            Route::get('/', [PartnerController::class, 'opportunities'])->middleware('permission:partners.opportunities.read');
            Route::post('/', [PartnerController::class, 'storeOpportunity'])->middleware('permission:partners.opportunities.manage');
            Route::get('/{uid}', [PartnerController::class, 'showOpportunity'])->middleware('permission:partners.opportunities.read');
            Route::put('/{uid}', [PartnerController::class, 'updateOpportunity'])->middleware('permission:partners.opportunities.manage');
            Route::post('/validate', [PartnerController::class, 'validateOpportunity'])->middleware('permission:partners.opportunities.manage');
            Route::post('/{uid}/close', [PartnerController::class, 'closeOpportunity'])->middleware('permission:partners.opportunities.manage');
            Route::post('/{uid}/approve', [PartnerController::class, 'approveOpportunity'])->middleware('permission:partners.opportunities.manage');
            Route::post('/{uid}/reject', [PartnerController::class, 'rejectOpportunity'])->middleware('permission:partners.opportunities.manage');
            Route::post('/{uid}/convert', [PartnerController::class, 'convertOpportunity'])->middleware('permission:partners.opportunities.manage');
        });
    });

    Route::prefix('partner-resources')->group(function () {
        Route::get('/types', [PartnerResourceController::class, 'types'])->middleware('permission:partners.resources.read');
        Route::get('/', [PartnerResourceController::class, 'index'])->middleware('permission:partners.resources.read');
        Route::post('/', [PartnerResourceController::class, 'store'])->middleware('permission:partners.resources.manage');
        Route::get('/{uid}/download', [PartnerResourceController::class, 'download'])->middleware('permission:partners.resources.read');
        Route::put('/{uid}', [PartnerResourceController::class, 'update'])->middleware('permission:partners.resources.manage');
        Route::delete('/{uid}', [PartnerResourceController::class, 'destroy'])->middleware('permission:partners.resources.manage');
        Route::post('/{uid}/assign', [PartnerResourceController::class, 'assign'])->middleware('permission:partners.resources.manage');
    });

    Route::prefix('projects')->group(function () {
        Route::get('/resource-roles', [ProjectController::class, 'resourceRoles'])->middleware('permission:projects.read');
        Route::get('/statuses', [StaticOptionController::class, 'projectStatuses'])->middleware('permission:projects.read');
        Route::get('/', [ProjectController::class, 'index'])->middleware('permission:projects.read');
        Route::post('/', [ProjectController::class, 'store'])->middleware('permission:projects.manage');
        Route::get('/{uid}', [ProjectController::class, 'show'])->middleware('permission:projects.read');
        Route::put('/{uid}', [ProjectController::class, 'update'])->middleware('permission:projects.manage');
        Route::post('/{uid}/milestones', [ProjectController::class, 'storeMilestone'])->middleware('permission:projects.manage');
        Route::get('/{uid}/assignments', [ProjectController::class, 'assignments'])->middleware('permission:projects.read');
        Route::post('/{uid}/assignments', [ProjectController::class, 'storeAssignment'])->middleware('permission:projects.manage');
        Route::delete('/{uid}/assignments/{assignmentUid}', [ProjectController::class, 'destroyAssignment'])->middleware('permission:projects.manage');
        Route::get('/{uid}/team', [ProjectController::class, 'team'])->middleware('permission:projects.read');
        Route::get('/{uid}/progress', [ProjectController::class, 'progress'])->middleware('permission:projects.read');
    });

    Route::put('/milestones/{uid}', [ProjectController::class, 'updateMilestone'])->middleware('permission:projects.manage');
    Route::get('/milestones/statuses', [StaticOptionController::class, 'milestoneStatuses'])->middleware('permission:projects.read');

    Route::prefix('finance')->group(function () {
        Route::get('/records', [FinancialOperationsController::class, 'index'])->middleware('permission:finance.read');
        Route::post('/import', [FinancialOperationsController::class, 'import'])->middleware('permission:finance.manage');
        Route::get('/invoices', [FinancialOperationsController::class, 'invoices'])->middleware('permission:finance.read');
        Route::get('/invoices/{uid}', [FinancialOperationsController::class, 'showInvoice'])->middleware('permission:finance.read');
        Route::post('/invoices/export', [FinancialOperationsController::class, 'exportInvoices'])->middleware('permission:finance.read');
        Route::post('/invoices', [FinancialOperationsController::class, 'createInvoice'])->middleware('permission:finance.manage');
        Route::post('/invoices/{uid}/send', [FinancialOperationsController::class, 'sendInvoice'])->middleware('permission:finance.manage');
        Route::get('/payments', [FinancialOperationsController::class, 'payments'])->middleware('permission:finance.read');
        Route::post('/payments', [FinancialOperationsController::class, 'registerPayment'])->middleware('permission:finance.manage');
        Route::get('/alerts', [FinancialOperationsController::class, 'alerts'])->middleware('permission:finance.read');
        Route::post('/sync-overdue', [FinancialOperationsController::class, 'syncOverdueInvoices'])->middleware('permission:finance.manage');
        Route::get('/credit/rules', [FinancialOperationsController::class, 'creditRules'])->middleware('permission:finance.read');
        Route::put('/credit/rules', [FinancialOperationsController::class, 'updateCreditRules'])->middleware('permission:finance.manage');
        Route::get('/credit/exceptions', [FinancialOperationsController::class, 'creditExceptions'])->middleware('permission:finance.read');
        Route::post('/credit/exceptions', [FinancialOperationsController::class, 'storeCreditException'])->middleware('permission:finance.manage');
        Route::put('/credit/exceptions/{uid}', [FinancialOperationsController::class, 'updateCreditException'])->middleware('permission:finance.manage');
        Route::get('/credit/{type}/{uid}', [FinancialOperationsController::class, 'creditSummary'])->middleware('permission:finance.read');
        Route::put('/credit/{type}/{uid}', [FinancialOperationsController::class, 'updateCreditProfile'])->middleware('permission:finance.manage');
        Route::get('/customer/{type}/{uid}/summary', [FinancialOperationsController::class, 'customerSummary'])->middleware('permission:finance.read');
        Route::get('/dashboard', [FinancialOperationsController::class, 'dashboard'])->middleware('permission:finance.read');
    });

    Route::get('/invoices', [FinancialOperationsController::class, 'invoices'])->middleware('permission:finance.read');
    Route::get('/invoices/statuses', [StaticOptionController::class, 'invoiceStatuses'])->middleware('permission:finance.read');
    Route::get('/invoices/{uid}', [FinancialOperationsController::class, 'showInvoice'])->middleware('permission:finance.read');

    Route::prefix('currency')->middleware('feature:multicurrency')->group(function () {
        Route::get('/rates', [CurrencyController::class, 'rates'])->middleware('permission:finance.read');
        Route::post('/rates', [CurrencyController::class, 'storeRate'])->middleware('permission:finance.manage');
        Route::post('/convert', [CurrencyController::class, 'convert'])->middleware('permission:finance.read');
    });

    Route::post('/sales/finance/invoices/export', [FinancialOperationsController::class, 'exportInvoices'])->middleware('permission:finance.read');

    Route::prefix('payments')->group(function () {
        Route::post('/', [FinancialOperationsController::class, 'registerPayment'])->middleware('permission:finance.manage');
        Route::get('/{invoiceUid}', [FinancialOperationsController::class, 'paymentHistory'])->middleware('permission:finance.read');
    });

    Route::post('/search', [SearchController::class, 'search'])->middleware('permission:search.use');
    Route::post('/search/export', [SearchController::class, 'export'])->middleware('permission:search.use');
    Route::get('/dashboard/core', [DashboardController::class, 'core'])->middleware('permission:dashboard.read');
    Route::get('/schedule', [ScheduleController::class, 'index'])->middleware('permission:activities.read');

    Route::prefix('custom-fields')->middleware('feature:custom_fields')->group(function () {
        Route::get('/modules', [CustomFieldController::class, 'modules'])->middleware('permission:custom-fields.manage');
        Route::get('/', [CustomFieldController::class, 'index'])->middleware('permission:custom-fields.manage');
        Route::post('/', [CustomFieldController::class, 'store'])->middleware('permission:custom-fields.manage');
        Route::put('/{uid}', [CustomFieldController::class, 'update'])->middleware('permission:custom-fields.manage');
        Route::delete('/{uid}', [CustomFieldController::class, 'destroy'])->middleware('permission:custom-fields.manage');
        Route::post('/value', [CustomFieldController::class, 'assign'])->middleware('permission:custom-fields.manage');
    });

    Route::prefix('settings')->group(function () {
        Route::get('/localization', [SettingsController::class, 'localization']);
        Route::get('/localization/options', [SettingsController::class, 'localizationOptions']);
        Route::get('/countries', [SettingsController::class, 'countries']);
        Route::get('/cities', [SettingsController::class, 'cities']);
        Route::put('/localization', [SettingsController::class, 'updateLocalization'])->middleware('permission:settings.manage');
    });

    Route::prefix('tenant')->group(function () {
        Route::get('/payment-methods', [TenantOptionController::class, 'paymentMethods']);
        Route::get('/lead-origins', [TenantOptionController::class, 'leadOrigins']);
        Route::get('/institution-types', [TenantOptionController::class, 'institutionTypes']);
        Route::get('/company-sizes', [TenantOptionController::class, 'companySizes']);
        Route::get('/industries', [TenantOptionController::class, 'industries']);
        Route::get('/opportunity-products', [TenantOptionController::class, 'opportunityProducts']);
        Route::get('/lost-reason-categories', [TenantOptionController::class, 'lostReasonCategories']);
        Route::get('/activity-types', [TenantOptionController::class, 'activityTypes']);
        Route::get('/commission-plan-types', [TenantOptionController::class, 'commissionPlanTypes']);
    });

    Route::prefix('reports')->middleware('feature:reports')->group(function () {
        Route::get('/sales', [ReportController::class, 'sales'])->middleware('permission:reports.read');
        Route::post('/sales/export', [ReportController::class, 'exportSales'])->middleware('permission:reports.read');
        Route::get('/inventory', [ReportController::class, 'inventory'])->middleware('permission:reports.read');
        Route::post('/inventory/export', [ReportController::class, 'exportInventory'])->middleware('permission:reports.read');
        Route::get('/filters', [ReportController::class, 'filters'])->middleware('permission:reports.read');
    });

    Route::get('/metrics/my-usage', [MetricsController::class, 'myUsage'])->middleware('permission:metrics.read');
    Route::get('/logs', [LogController::class, 'index'])->middleware('permission:logs.read');
});

Route::middleware(['auth:sanctum', 'full.access', 'platform.admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->middleware('permission:admin.dashboard.read');
    Route::get('/plan-modules', [PlanController::class, 'modules'])->middleware('permission:plans.manage');

    Route::prefix('tenants')->group(function () {
        Route::get('/', [AdminTenantController::class, 'index'])->middleware('permission:admin.tenants.manage');
        Route::post('/', [AdminTenantController::class, 'store'])->middleware('permission:admin.tenants.manage');
        Route::get('/{uid}', [AdminTenantController::class, 'show'])->middleware('permission:admin.tenants.manage');
        Route::put('/{uid}', [AdminTenantController::class, 'update'])->middleware('permission:admin.tenants.manage');
        Route::delete('/{uid}', [AdminTenantController::class, 'destroy'])->middleware('permission:admin.tenants.manage');
        Route::post('/{uid}/suspend', [AdminTenantController::class, 'suspend'])->middleware('permission:admin.tenants.manage');
        Route::post('/{uid}/activate', [AdminTenantController::class, 'activate'])->middleware('permission:admin.tenants.manage');
        Route::post('/{uid}/archive', [AdminTenantController::class, 'archive'])->middleware('permission:admin.tenants.manage');
        Route::post('/{uid}/restore', [AdminTenantController::class, 'restore'])->middleware('permission:admin.tenants.manage');
        Route::get('/{uid}/permissions', [AdminTenantController::class, 'permissions'])->middleware('permission:admin.tenants.manage');
        Route::get('/{uid}/users', [AdminTenantController::class, 'users'])->middleware('permission:admin.tenants.manage');
        Route::post('/{uid}/users', [AdminTenantController::class, 'createUser'])->middleware('permission:admin.tenants.manage');
        Route::put('/{uid}/users/{userUid}', [AdminTenantController::class, 'updateUser'])->middleware('permission:admin.tenants.manage');
        Route::delete('/{uid}/users/{userUid}', [AdminTenantController::class, 'destroyUser'])->middleware('permission:admin.tenants.manage');
        Route::post('/{uid}/users/{userUid}/lock', [AdminTenantController::class, 'lockUser'])->middleware('permission:admin.tenants.manage');
        Route::post('/{uid}/users/{userUid}/unlock', [AdminTenantController::class, 'unlockUser'])->middleware('permission:admin.tenants.manage');
        Route::post('/{uid}/users/{userUid}/2fa/reset', [AdminTenantController::class, 'resetUserTwoFactor'])->middleware('permission:admin.tenants.manage');
        Route::post('/{uid}/support-login', [AdminTenantController::class, 'supportLogin'])->middleware('permission:admin.tenants.support');
    });

    Route::prefix('billing')->group(function () {
        Route::get('/', [AdminBillingController::class, 'index'])->middleware('permission:admin.billing.manage');
        Route::get('/summary', [AdminBillingController::class, 'summary'])->middleware('permission:admin.billing.manage');
        Route::get('/export', [AdminBillingController::class, 'export'])->middleware('permission:admin.billing.manage');
        Route::post('/mark-paid-bulk', [AdminBillingController::class, 'markPaidBulk'])->middleware('permission:admin.billing.manage');
        Route::post('/{uid}/mark-paid', [AdminBillingController::class, 'markPaid'])->middleware('permission:admin.billing.manage');
    });

    Route::prefix('telemetry')->group(function () {
        Route::get('/summary', [AdminTelemetryController::class, 'summary'])->middleware('permission:admin.telemetry.read');
        Route::get('/stats', [AdminTelemetryController::class, 'stats'])->middleware('permission:admin.telemetry.read');
        Route::get('/logs', [AdminTelemetryController::class, 'logs'])->middleware('permission:admin.telemetry.read');
        Route::get('/alerts', [AdminTelemetryController::class, 'alerts'])->middleware('permission:admin.alerts.manage');
        Route::post('/alerts/evaluate', [AdminTelemetryController::class, 'evaluateAlerts'])->middleware('permission:admin.alerts.manage');
        Route::post('/alerts', [AdminTelemetryController::class, 'storeAlert'])->middleware('permission:admin.alerts.manage');
        Route::put('/alerts/{uid}', [AdminTelemetryController::class, 'updateAlert'])->middleware('permission:admin.alerts.manage');
        Route::post('/alerts/{uid}/toggle', [AdminTelemetryController::class, 'toggleAlert'])->middleware('permission:admin.alerts.manage');
        Route::delete('/alerts/{uid}', [AdminTelemetryController::class, 'destroyAlert'])->middleware('permission:admin.alerts.manage');
    });

    Route::prefix('platform')->group(function () {
        Route::get('/roles', [AdminPlatformRoleController::class, 'index'])->middleware('platform.permission:admin.tenants.manage');
        Route::post('/roles', [AdminPlatformRoleController::class, 'store'])->middleware('platform.permission:admin.tenants.manage');
        Route::get('/roles/permissions', [AdminPlatformRoleController::class, 'permissions'])->middleware('platform.permission:admin.tenants.manage');
        Route::get('/roles/{uid}', [AdminPlatformRoleController::class, 'show'])->middleware('platform.permission:admin.tenants.manage');
        Route::put('/roles/{uid}', [AdminPlatformRoleController::class, 'update'])->middleware('platform.permission:admin.tenants.manage');
        Route::delete('/roles/{uid}', [AdminPlatformRoleController::class, 'destroy'])->middleware('platform.permission:admin.tenants.manage');

        Route::get('/users', [AdminPlatformUserController::class, 'index'])->middleware('platform.permission:admin.tenants.manage');
        Route::post('/users', [AdminPlatformUserController::class, 'store'])->middleware('platform.permission:admin.tenants.manage');
        Route::get('/users/{uid}', [AdminPlatformUserController::class, 'show'])->middleware('platform.permission:admin.tenants.manage');
        Route::put('/users/{uid}', [AdminPlatformUserController::class, 'update'])->middleware('platform.permission:admin.tenants.manage');
        Route::post('/users/{uid}/2fa/reset', [AdminPlatformUserController::class, 'resetTwoFactor'])->middleware('platform.permission:admin.tenants.manage');
        Route::post('/users/{uid}/roles', [AdminPlatformUserController::class, 'assignRole'])->middleware('platform.permission:admin.tenants.manage');
        Route::delete('/users/{uid}/roles/{roleUid}', [AdminPlatformUserController::class, 'removeRole'])->middleware('platform.permission:admin.tenants.manage');
    });
});
