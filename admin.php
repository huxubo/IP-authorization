<?php
// admin.php
require_once 'AuthService.php';

class IPAdminManager
{
    private $authService;
    private $dbFile = 'SQLite.db';
    
    public function __construct()
    {
        $this->authService = new AuthService($this->dbFile);
        $this->checkAuth();
    }
    
    private function checkAuth()
    {
        // 启动会话
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // 检查是否已登录
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            // 未登录，重定向到登录页面
            header('Location: login.php');
            exit;
        }

        // 会话超时检查
        $sessionTimeout = $this->getSessionTimeout();
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > $sessionTimeout)) {
            // 清理并跳转到登录页
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
            header('Location: login.php');
            exit;
        }

        // 更新最后活动时间
        $_SESSION['login_time'] = time();
    }

    private function getSessionTimeout(): int
    {
        return $this->authService->getSessionTimeout();
    }
    
    private function authenticate($password)
    {
        $adminPassword = $this->authService->getAdminPassword();
        return password_verify($password, $adminPassword);
    }
    
    public function handleRequest()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            $this->handleAjaxRequest();
            return;
        }
        
        $this->showManagementPage();
    }
    
    private function handleAjaxRequest()
    {
        $action = $_POST['action'];
        
        switch ($action) {
            case 'add':
                $this->handleAddIP();
                break;
            case 'edit':
                $this->handleEditIP();
                break;
            case 'delete':
                $this->handleDeleteIP();
                break;
            case 'change_password':
                $this->handleChangePassword();
                break;
            case 'logout':
                $this->handleLogout();
                break;
            case 'get_ips':
                $this->handleGetIPs();
                break;
            default:
                $this->jsonResponse(false, '未知操作');
        }
    }
    
    private function handleAddIP()
    {
        $ip = trim($_POST['ip'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if (empty($ip)) {
            $this->jsonResponse(false, 'IP地址不能为空');
            return;
        }
        
        try {
            $success = $this->authService->addAllowedIp($ip, $description);
            $this->jsonResponse($success, $success ? 'IP添加成功' : 'IP已存在');
        } catch (Exception $e) {
            $this->jsonResponse(false, '添加失败：' . $e->getMessage());
        }
    }
    
    private function handleEditIP()
    {
        $originalIp = trim($_POST['original_ip'] ?? '');
        $newIp = trim($_POST['ip'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        if (empty($originalIp) || empty($newIp)) {
            $this->jsonResponse(false, 'IP地址不能为空');
            return;
        }
        
        try {
            $success = $this->authService->renameAllowedIp($originalIp, $newIp, $description);
            $this->jsonResponse($success, $success ? 'IP修改成功' : 'IP修改失败');
        } catch (Exception $e) {
            $this->jsonResponse(false, '修改失败：' . $e->getMessage());
        }
    }
    
    private function handleDeleteIP()
    {
        $ip = $_POST['ip'] ?? '';
        
        if (empty($ip)) {
            $this->jsonResponse(false, 'IP地址不能为空');
            return;
        }
        
        try {
            $success = $this->authService->removeAllowedIp($ip);
            $this->jsonResponse($success, $success ? 'IP删除成功' : 'IP不存在');
        } catch (Exception $e) {
            $this->jsonResponse(false, '删除失败：' . $e->getMessage());
        }
    }
    
    private function handleChangePassword()
    {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword)) {
            $this->jsonResponse(false, '密码不能为空');
            return;
        }
        
        if ($newPassword !== $confirmPassword) {
            $this->jsonResponse(false, '新密码不一致');
            return;
        }
        
        if (!$this->authenticate($currentPassword)) {
            $this->jsonResponse(false, '当前密码错误');
            return;
        }
        
        $success = $this->authService->updateAdminPassword($newPassword);
        $this->jsonResponse($success, $success ? '密码修改成功' : '密码修改失败');
    }
    
    private function handleLogout()
    {
        session_start();
        $_SESSION = [];
        session_destroy();
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        
        $this->jsonResponse(true, '退出成功', ['redirect' => 'login.php']);
    }
    
    private function handleGetIPs()
    {
        try {
            $allowedIps = $this->authService->getAllowedIps();
            $ips = is_array($allowedIps) ? $allowedIps : [];
            
            // 处理搜索
            $search = trim($_POST['search'] ?? '');
            if (!empty($search)) {
                $ips = array_filter($ips, function($ip) use ($search) {
                    return stripos($ip['ip'], $search) !== false || 
                           stripos($ip['description'] ?? '', $search) !== false;
                });
            }
            
            // 处理分页
            $page = intval($_POST['page'] ?? 1);
            $perPage = intval($_POST['per_page'] ?? 10);
            $total = count($ips);
            $totalPages = ceil($total / $perPage);
            $page = max(1, min($page, $totalPages));
            
            $start = ($page - 1) * $perPage;
            $pagedIps = array_slice($ips, $start, $perPage);
            
            $this->jsonResponse(true, '获取IP列表成功', [
                'ips' => array_values($pagedIps),
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages,
                    'has_prev' => $page > 1,
                    'has_next' => $page < $totalPages
                ]
            ]);
        } catch (Exception $e) {
            $this->jsonResponse(false, '获取IP列表失败：' . $e->getMessage());
        }
    }
    
    private function jsonResponse($success, $message, $data = [])
    {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }
    
    private function showManagementPage()
    {
        $currentIp = $_SERVER['REMOTE_ADDR'];
        ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP授权管理系统</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 0.5rem;
            min-width: 200px;
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            border: 1px solid #e5e7eb;
            z-index: 50;
        }
        .dropdown-menu.show {
            display: block;
        }
        .pagination-btn {
            transition: all 0.2s ease;
        }
        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* ========= 仅移动端 IP 列表卡片样式（只保留下边框 + 调整边距） ========= */
        #mobileIpList > :not([hidden]) ~ :not([hidden]) {
            margin-top: 0 !important; /* 取消 space-y-3，用下边框分隔 */
        }
        
        /* 卡片本体：列表行风格 */
        #mobileIpList .ip-card {
            border: none !important;
            border-bottom: 1px solid #e5e7eb !important;
            border-radius: 0 !important;
            box-shadow: none !important;
        
            padding: 10px 0px !important;      /* 文字留白更舒服 */
            background: #fff !important;        /* 保持白底，显得干净 */
            transition: background-color .15s ease, transform .15s ease;
        }
        
        /* 轻微悬浮反馈（不加阴影、不加边框） */
        #mobileIpList .ip-card:hover {
            background: #f9fafb !important;
        }
        
        /* 标题行：更紧凑但不拥挤 */
        #mobileIpList .ip-card .mb-3 {
            margin-bottom: 8px !important;
        }
        #mobileIpList .ip-card h4 {
            margin: 0 !important;
            line-height: 1.25 !important;
            letter-spacing: 0.2px;
        }
        
        /* 信息区：行间距更舒服 */
        #mobileIpList .ip-card .space-y-2 > :not([hidden]) ~ :not([hidden]) {
            margin-top: 6px !important;
        }
        #mobileIpList .ip-card .text-sm {
            line-height: 1.35 !important;
        }
        
        /* label（IP地址/添加时间/更新时间）颜色稍淡一点 */
        #mobileIpList .ip-card span.font-medium {
            color: #6b7280 !important; /* gray-500 */
        }
        
        /* IP 显示：做成小"胶囊"块，更清楚 */
        #mobileIpList .ip-card .font-mono {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 6px;
            background: #f3f4f6; /* gray-100 */
            color: #111827;      /* gray-900 */
            letter-spacing: 0.2px;
        }

        /* IP地址显示优化 - 完整版 */
        .ip-address {
            font-size: 11px !important;
            word-break: break-all !important;
            word-wrap: break-word !important;
            white-space: normal !important;
            line-height: 1.3 !important;
            color: #1f2937 !important;
        }
        
        /* 移动端IP地址样式 */
        #mobileIpList .ip-address {
            background: #f3f4f6 !important;
            padding: 4px 8px !important;
            border-radius: 6px !important;
            display: inline-block !important;
            margin: 2px 0 !important;
            border: 1px solid #e5e7eb !important;
            max-width: 100% !important;
            box-sizing: border-box !important;
        }
        
        /* 桌面端表格中的IP地址 */
        #desktopIpList .ip-address {
            max-width: 200px !important;
            min-width: 120px !important;
            padding: 2px 4px !important;
            background: #f9fafb !important;
            border-radius: 4px !important;
            border: 1px solid #f3f4f6 !important;
        }
        
        /* 响应式调整 */
        @media (max-width: 768px) {
            .ip-address {
                font-size: 10px !important;
                line-height: 1.4 !important;
            }
        }
        
        @media (min-width: 1024px) {
            #desktopIpList .ip-address {
                max-width: 250px !important;
                font-size: 12px !important;
            }
        }
        
        /* 确保表格单元格正确换行 */
        #desktopIpList td {
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- 顶部导航栏 -->
    <header class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-3 sm:px-4 md:px-6 lg:px-8">
            <div class="flex justify-between items-center h-14 md:h-16">
                <!-- 左侧：Logo和标题 -->
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-gradient-to-r from-blue-500 to-purple-600 rounded-lg flex items-center justify-center">
                            <svg class="w-4 h-4 md:w-5 md:h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="ml-2 md:ml-3">
                        <h1 class="text-lg md:text-xl font-semibold text-gray-900">IP授权管理</h1>
                        <p class="text-xs md:text-sm text-gray-500 hidden sm:block">当前IP: <?php echo htmlspecialchars($currentIp); ?></p>
                    </div>
                </div>

                <!-- 右侧：用户菜单 -->
                <div class="flex items-center space-x-2 md:space-x-4">
                    <!-- 用户下拉菜单 -->
                    <div class="relative">
                        <button id="userMenuButton" class="flex items-center space-x-1 md:space-x-3 p-1 md:p-2 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="w-6 h-6 md:w-8 md:h-8 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center">
                                <svg class="w-3 h-3 md:w-4 md:h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                            </div>
                            <div class="text-left hidden md:block">
                                <p class="text-xs md:text-sm font-medium text-gray-900">管理员</p>
                                <p class="text-xs text-gray-500"><?php echo date('H:i', $_SESSION['login_time']); ?> 登录</p>
                            </div>
                            <svg class="w-3 h-3 md:w-4 md:h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        
                        <!-- 下拉菜单内容 -->
                        <div id="userMenu" class="dropdown-menu">
                            <div class="py-2">
                                <div class="px-3 md:px-4 py-2 border-b border-gray-100">
                                    <p class="text-sm font-medium text-gray-900">管理员</p>
                                    <p class="text-xs text-gray-500">登录时间: <?php echo date('Y-m-d H:i:s', $_SESSION['login_time']); ?></p>
                                </div>
                                <button id="changePasswordBtn" class="w-full text-left px-3 md:px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center space-x-2">
                                    <svg class="w-3 h-3 md:w-4 md:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                    </svg>
                                    <span>修改密码</span>
                                </button>
                                <button id="logoutBtn" class="w-full text-left px-3 md:px-4 py-2 text-sm text-red-600 hover:bg-red-50 flex items-center space-x-2">
                                    <svg class="w-3 h-3 md:w-4 md:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                    </svg>
                                    <span>退出登录</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- 主内容区 -->
    <main class="mx-auto px-3 sm:px-4 md:px-6 py-4 md:py-6 md:max-w-[980px] lg:max-w-[980px] xl:max-w-[980px]">
        <!-- 消息提示 -->
        <div id="message" class="hidden fixed top-4 right-4 z-50 px-3 py-2 md:px-4 md:py-3 rounded-lg shadow-lg transition-all duration-300 text-sm max-w-xs md:max-w-md"></div>

        <!-- IP列表 -->
        <div class="bg-white rounded-xl border border-gray-200 card-hover">
            <div class="p-4 md:p-6 border-b border-gray-200">
                <!-- 第一行：标题和统计（在移动端同一行） -->
                <div class="flex items-center justify-between mb-4">
                    <!-- 左侧：标题 -->
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">已授权IP列表</h3>
                    </div>
                    
                    <!-- 右侧：统计和刷新（移动端和PC端都显示） -->
                    <div class="flex items-center space-x-2">
                        <span id="ipCount" class="text-sm text-gray-600 whitespace-nowrap">共 0 个IP</span>
                        <button id="refreshListBtn" class="text-blue-600 hover:text-blue-700 text-sm font-medium flex items-center whitespace-nowrap px-2 py-1 hover:bg-blue-50 rounded">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                            </svg>
                            <span class="ml-1 hidden sm:inline">刷新</span>
                        </button>
                    </div>
                </div>
                
                <!-- 第二行：搜索和添加按钮 -->
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <!-- 搜索框 -->
                    <div class="relative flex-grow">
                        <input type="text" id="searchInput" placeholder="搜索IP或描述..." 
                            class="w-full px-3 py-2 pl-9 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm">
                        <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                    
                    <!-- 添加IP按钮 -->
                    <button id="addIpBtn" class="flex items-center justify-center space-x-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors text-sm whitespace-nowrap">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        <span>添加IP</span>
                    </button>
                </div>
            </div>
            
            <div class="p-4 md:p-6">
                <!-- 移动端：卡片式列表 -->
                <div id="mobileIpList" class="space-y-3 md:hidden">
                    <!-- 卡片将通过JavaScript动态加载 -->
                </div>
                
                <!-- PC端：表格列表 -->
                <div id="desktopIpList" class="hidden md:block">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-full">
                            <thead>
                                <tr class="border-b bg-gray-50">
                                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">名称</th>
                                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">IP地址</th>
                                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">添加时间</th>
                                    <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">操作</th>
                                </tr>
                            </thead>
                            <tbody id="ipListBody">
                                <tr id="loadingRow">
                                    <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                        <div class="inline-flex items-center">
                                            <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            加载中...
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- 分页控件 -->
                <div id="pagination" class="hidden mt-6">
                    <!-- 移动端：垂直布局 -->
                    <div class="block md:hidden">
                        <!-- 统计信息 -->
                        <div class="flex justify-between items-center mb-3 text-sm text-gray-700">
                            <span>第 <span id="paginationStart">0</span>-<span id="paginationEnd">0</span> 条</span>
                            <span>共 <span id="paginationTotal">0</span> 条</span>
                        </div>
                        
                        <!-- 每页条数选择 -->
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-sm text-gray-600">每页显示：</span>
                            <select id="perPageSelect" class="text-sm border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="5">5 条</option>
                                <option value="10" selected>10 条</option>
                                <option value="20">20 条</option>
                                <option value="50">50 条</option>
                            </select>
                        </div>
                        
                        <!-- 分页导航 -->
                        <div class="flex items-center justify-center space-x-1">
                            <button id="firstPageBtn" class="pagination-btn p-2 text-sm border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
                                </svg>
                            </button>
                            <button id="prevPageBtn" class="pagination-btn p-2 text-sm border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                </svg>
                            </button>
                            <div id="pageNumbers" class="flex space-x-1"></div>
                            <button id="nextPageBtn" class="pagination-btn p-2 text-sm border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                            <button id="lastPageBtn" class="pagination-btn p-2 text-sm border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                    </div>
                    
                    <!-- 桌面端：水平布局 -->
                    <div class="hidden md:flex items-center justify-between">
                        <div class="text-sm text-gray-700">
                            显示 <span id="desktopPaginationStart">0</span> 到 <span id="desktopPaginationEnd">0</span> 项，共 <span id="desktopPaginationTotal">0</span> 项
                        </div>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-gray-600">每页：</span>
                            <select id="desktopPerPageSelect" class="text-sm border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="5">5 条</option>
                                <option value="10" selected>10 条</option>
                                <option value="20">20 条</option>
                                <option value="50">50 条</option>
                            </select>
                            <div class="flex items-center space-x-1">
                                <button id="desktopFirstPageBtn" class="pagination-btn px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                                    首页
                                </button>
                                <button id="desktopPrevPageBtn" class="pagination-btn px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                                    上页
                                </button>
                                <div id="desktopPageNumbers" class="flex space-x-1"></div>
                                <button id="desktopNextPageBtn" class="pagination-btn px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                                    下页
                                </button>
                                <button id="desktopLastPageBtn" class="pagination-btn px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                                    末页
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- 修改密码弹出层 -->
    <div id="passwordModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-3 md:p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-2">
            <div class="p-4 md:p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">修改密码</h3>
            </div>
            <form id="changePasswordForm" class="p-4 md:p-6 space-y-3 md:space-y-4">
                <input type="hidden" name="action" value="change_password">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 md:mb-2">当前密码</label>
                    <input type="password" name="current_password" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm md:text-base">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 md:mb-2">新密码</label>
                    <input type="password" name="new_password" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm md:text-base">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1 md:mb-2">确认新密码</label>
                    <input type="password" name="confirm_password" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm md:text-base">
                </div>
            </form>
            <div class="p-4 md:p-6 border-t border-gray-200 flex justify-end space-x-2 md:space-x-3">
                <button type="button" id="cancelPasswordBtn" class="px-3 py-2 md:px-4 md:py-2 text-gray-600 hover:text-gray-800 font-medium text-sm">
                    取消
                </button>
                <button type="button" id="savePasswordBtn" class="px-3 py-2 md:px-4 md:py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors text-sm">
                    修改密码
                </button>
            </div>
        </div>
    </div>

    <!-- 添加/编辑IP的弹出层 -->
    <div id="ipModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-3 md:p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md mx-2">
            <div class="p-4 md:p-6 border-b border-gray-200">
                <h3 id="modalTitle" class="text-lg font-semibold text-gray-800">添加IP地址</h3>
            </div>
            <form id="ipForm" class="p-4 md:p-6 space-y-3 md:space-y-4">
                <input type="hidden" id="originalIp" name="original_ip" value="">
                <input type="hidden" id="modalAction" name="action" value="add">
                <div>
                    <label for="modalIp" class="block text-sm font-medium text-gray-700 mb-1 md:mb-2">IP地址</label>
                    <input type="text" id="modalIp" name="ip" placeholder="例如：<?php echo htmlspecialchars($currentIp); ?>" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm md:text-base">
                </div>
                <div>
                    <label for="modalDescription" class="block text-sm font-medium text-gray-700 mb-1 md:mb-2">描述</label>
                    <input type="text" id="modalDescription" name="description" placeholder="例如：办公室网络"
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm md:text-base">
                </div>
            </form>
            <div class="p-4 md:p-6 border-t border-gray-200 flex justify-between items-center">
                <div>
                    <button type="button" id="deleteBtn" class="hidden px-3 py-2 md:px-4 md:py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition-colors flex items-center text-sm">
                        <svg class="w-3 h-3 md:w-4 md:h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                        删除
                    </button>
                </div>
                <div class="flex space-x-2 md:space-x-3">
                    <button type="button" id="cancelBtn" class="px-3 py-2 md:px-4 md:py-2 text-gray-600 hover:text-gray-800 font-medium text-sm">
                        取消
                    </button>
                    <button type="button" id="saveBtn" class="px-3 py-2 md:px-4 md:py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors text-sm">
                        保存
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        /* ===========================
           全局状态
        =========================== */
        const state = {
            page: 1,
            perPage: 10,
            search: '',
            total: 0,
            totalPages: 1
        };
    
    /* ===========================
       DOM 工具
    =========================== */
    const $ = s => document.querySelector(s);
    const $$ = s => document.querySelectorAll(s);
    const on = (el, ev, fn) => el && el.addEventListener(ev, fn);
    const isMobile = () => window.innerWidth < 768;
    
    /* ===========================
       DOM 缓存
    =========================== */
    const els = {
        message: $('#message'),
        ipCount: $('#ipCount'),
        mobileList: $('#mobileIpList'),
        desktopWrap: $('#desktopIpList'),
        desktopBody: $('#ipListBody'),
        pagination: $('#pagination'),
        refreshBtn: $('#refreshListBtn'), // 添加刷新按钮引用
    
        // 分页容器
        pageNumbers: $('#pageNumbers'),
        dPageNumbers: $('#desktopPageNumbers'),
    
        firstBtns: $$('#firstPageBtn, #desktopFirstPageBtn'),
        prevBtns: $$('#prevPageBtn, #desktopPrevPageBtn'),
        nextBtns: $$('#nextPageBtn, #desktopNextPageBtn'),
        lastBtns: $$('#lastPageBtn, #desktopLastPageBtn'),
    
        perPageSelects: $$('#perPageSelect, #desktopPerPageSelect'),
    
        statStart: $('#paginationStart'),
        statEnd: $('#paginationEnd'),
        statTotal: $('#paginationTotal'),
        dStatStart: $('#desktopPaginationStart'),
        dStatEnd: $('#desktopPaginationEnd'),
        dStatTotal: $('#desktopPaginationTotal'),
    
        searchInput: $('#searchInput'),
    
        ipModal: $('#ipModal'),
        pwdModal: $('#passwordModal'),
        userMenu: $('#userMenu'),
    
        ipForm: $('#ipForm'),
        pwdForm: $('#changePasswordForm'),
    
        modalTitle: $('#modalTitle'),
        modalAction: $('#modalAction'),
        originalIp: $('#originalIp'),
        modalIp: $('#modalIp'),
        modalDesc: $('#modalDescription'),
        deleteBtn: $('#deleteBtn')
    };
    
    /* ===========================
       工具函数
    =========================== */
    function escapeHtml(str = '') {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
    
    function debounce(fn, delay = 300) {
        let t;
        return (...args) => {
            clearTimeout(t);
            t = setTimeout(() => fn(...args), delay);
        };
    }
    
    /* ===========================
       消息提示
    =========================== */
    function showMessage(msg, ok = true) {
        els.message.className =
            'fixed top-4 right-4 z-50 px-4 py-2 rounded-lg shadow ' +
            (ok ?
                'bg-green-100 text-green-700 border border-green-200' :
                'bg-red-100 text-red-700 border border-red-200');
        els.message.textContent = msg;
        els.message.classList.remove('hidden');
        setTimeout(() => els.message.classList.add('hidden'), 3000);
    }
    
    /* ===========================
       API
    =========================== */
    async function api(action, data = {}) {
        const fd = new FormData();
        fd.append('action', action);
        Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
        const res = await fetch('admin.php', {
            method: 'POST',
            body: fd
        });
        if (!res.ok) throw new Error('网络错误');
        return res.json();
    }
    
    /* ===========================
       列表渲染
    =========================== */
    function renderMobile(ips) {
        els.mobileList.innerHTML = ips.length ?
            ips
            .map(
                ip => `
              <div class="ip-card">
                <div class="flex justify-between mb-2">
                  <h4 class="font-medium">${escapeHtml(ip.description || '未命名')}</h4>
                  <button class="edit-btn text-blue-600 hover:text-blue-800 font-medium" data-ip="${ip.ip}" data-desc="${escapeHtml(ip.description || '')}">编辑</button>
                </div>
                <div class="text-sm text-gray-600 space-y-1">
                  <div><span class="ip-address font-mono">${ip.ip}</span></div>
                  <div><span class="font-medium text-gray-700">添加时间：</span>${ip.created_at}</div>
                  <div><span class="font-medium text-gray-700">更新时间：</span>${ip.updated_at || ip.created_at}</div>
                </div>
              </div>
            `
            )
            .join('') :
            `<div class="text-center py-6 text-gray-500">暂无数据</div>`;
    }
    
    function renderDesktop(ips) {
        els.desktopBody.innerHTML = ips.length ?
            ips
            .map(
                ip => `
              <tr class="border-b hover:bg-gray-50">
                <td class="px-4 py-3">${escapeHtml(ip.description || '未命名')}</td>
                <td class="px-4 py-3">
                    <span class="ip-address font-mono">${ip.ip}</span>
                </td>
                <td class="px-4 py-3">${ip.created_at}</td>
                <td class="px-4 py-3">
                  <button class="edit-btn text-blue-600 hover:text-blue-800 font-medium" data-ip="${ip.ip}" data-desc="${escapeHtml(ip.description || '')}">编辑</button>
                </td>
              </tr>
            `
            )
            .join('') :
            `<tr><td colspan="5" class="text-center py-8 text-gray-500">暂无数据</tr>`;
    }

    function renderList(ips) {
        if (isMobile()) {
            els.mobileList.style.display = '';
            els.desktopWrap.style.display = 'none';
            renderMobile(ips);
        } else {
            els.mobileList.style.display = 'none';
            els.desktopWrap.style.display = '';
            renderDesktop(ips);
        }
    }
    
    /* ===========================
       分页：核心
    =========================== */
    function buildPages(current, total, max = 5) {
        const half = Math.floor(max / 2);
        let start = Math.max(1, current - half);
        let end = Math.min(total, start + max - 1);
        if (end - start + 1 < max) start = Math.max(1, end - max + 1);
        return Array.from({
            length: end - start + 1
        }, (_, i) => start + i);
    }
    
    function renderPagination(p) {
        state.total = p.total;
        state.totalPages = p.total_pages;
        state.page = p.current_page;
        state.perPage = p.per_page;
    
        const start = state.total ? (state.page - 1) * state.perPage + 1 : 0;
        const end = Math.min(start + state.perPage - 1, state.total);
    
        // 统计
        els.statStart.textContent = start;
        els.statEnd.textContent = end;
        els.statTotal.textContent = state.total;
        els.dStatStart.textContent = start;
        els.dStatEnd.textContent = end;
        els.dStatTotal.textContent = state.total;
    
        els.perPageSelects.forEach(s => (s.value = state.perPage));
    
        // 翻页按钮禁用
        const hasPrev = state.page > 1;
        const hasNext = state.page < state.totalPages;
    
        els.firstBtns.forEach(b => (b.disabled = !hasPrev));
        els.prevBtns.forEach(b => (b.disabled = !hasPrev));
        els.nextBtns.forEach(b => (b.disabled = !hasNext));
        els.lastBtns.forEach(b => (b.disabled = !hasNext));
    
        // 页码
        const pages = buildPages(state.page, state.totalPages, isMobile() ? 3 : 5);
        [els.pageNumbers, els.dPageNumbers].forEach(container => {
            container.innerHTML = pages
                .map(
                    p => `
                <button data-page="${p}" class="px-3 py-1 text-sm border rounded
                  ${p === state.page ? 'bg-blue-600 text-white' : 'border-gray-300 hover:bg-gray-50'}">
                  ${p}
                </button>
              `
                )
                .join('');
        });
    
        els.pagination.classList.toggle('hidden', state.total === 0);
    }
    
    /* ===========================
       刷新
    =========================== */
    async function refresh() {
        try {
            // 显示加载状态
            if (els.desktopBody) {
                els.desktopBody.innerHTML = `
              <tr id="loadingRow">
                <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                  <div class="inline-flex items-center">
                    <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    加载中...
                  </div>
                </td>
              </tr>
            `;
            }
            if (els.mobileList) {
                els.mobileList.innerHTML = `
              <div class="text-center py-8 text-gray-500">
                <div class="inline-flex items-center">
                  <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  加载中...
                </div>
              </div>
            `;
            }
    
            const res = await api('get_ips', {
                page: state.page,
                per_page: state.perPage,
                search: state.search
            });
    
            if (!res.success) {
                showMessage(res.message, false);
                return;
            }
    
            els.ipCount.textContent = `共 ${res.data.pagination.total} 个IP`;
            renderList(res.data.ips || []);
            renderPagination(res.data.pagination);
    
            // showMessage('列表已刷新', true);
        } catch (error) {
            console.error('刷新失败:', error);
            showMessage('刷新失败，请检查网络连接', false);
    
            // 显示错误状态
            if (els.desktopBody) {
                els.desktopBody.innerHTML = `
              <tr>
                <td colspan="5" class="text-center py-8 text-red-500">
                  <div class="inline-flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    加载失败，请重试
                  </div>
                </td>
              </tr>
            `;
            }
            if (els.mobileList) {
                els.mobileList.innerHTML = `
              <div class="text-center py-8 text-red-500">
                <div class="inline-flex items-center">
                  <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    加载失败，请重试
                </div>
              </div>
            `;
            }
        }
    }
    
    /* ===========================
       模态 & 操作
    =========================== */
    function closeModals() {
        els.ipModal.classList.add('hidden');
        els.pwdModal.classList.add('hidden');
        els.userMenu.classList.remove('show');
    }
    
    function openAdd() {
        els.modalTitle.textContent = '添加IP';
        els.modalAction.value = 'add';
        els.originalIp.value = '';
        els.modalIp.value = '';
        els.modalDesc.value = '';
        els.deleteBtn.classList.add('hidden');
        els.ipModal.classList.remove('hidden');
    }
    
    function openEdit(ip, desc) {
        els.modalTitle.textContent = '编辑IP';
        els.modalAction.value = 'edit';
        els.originalIp.value = ip;
        els.modalIp.value = ip;
        els.modalDesc.value = desc || '';
        els.deleteBtn.classList.remove('hidden');
        els.ipModal.classList.remove('hidden');
    }
    
    /* ===========================
       翻页按钮绑定（修复：首页/上页/下页/末页无效）
    =========================== */
    function gotoPage(p) {
        const total = state.totalPages || 1;
        const page = Math.max(1, Math.min(p, total));
        if (page === state.page) return;
        state.page = page;
        refresh();
    }
    
    els.firstBtns.forEach(btn =>
        on(btn, 'click', e => {
            e.preventDefault();
            gotoPage(1);
        })
    );
    
    els.prevBtns.forEach(btn =>
        on(btn, 'click', e => {
            e.preventDefault();
            gotoPage(state.page - 1);
        })
    );
    
    els.nextBtns.forEach(btn =>
        on(btn, 'click', e => {
            e.preventDefault();
            gotoPage(state.page + 1);
        })
    );
    
    els.lastBtns.forEach(btn =>
        on(btn, 'click', e => {
            e.preventDefault();
            gotoPage(state.totalPages);
        })
    );
    
    /* ===========================
       行为绑定
    =========================== */
    // 修复：刷新按钮事件绑定
    on(els.refreshBtn, 'click', function(e) {
        e.preventDefault();
        refresh();
    });
    
    // 用户菜单
    on($('#userMenuButton'), 'click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        els.userMenu.classList.toggle('show');
    });
    
    on($('#changePasswordBtn'), 'click', function(e) {
        e.preventDefault();
        closeModals();
        els.pwdModal.classList.remove('hidden');
    });
    
    on($('#addIpBtn'), 'click', openAdd);
    
    on($('#saveBtn'), 'click', async () => {
        const r = await fetch('admin.php', {
            method: 'POST',
            body: new FormData(els.ipForm)
        }).then(r => r.json());
        showMessage(r.message, r.success);
        if (r.success) {
            closeModals();
            refresh();
        }
    });
    
    on(els.deleteBtn, 'click', async () => {
        if (!confirm('确定删除这个IP吗？删除后无法恢复。')) return;
        const r = await api('delete', {
            ip: els.originalIp.value
        });
        showMessage(r.message, r.success);
        if (r.success) {
            closeModals();
            refresh();
        }
    });
    
    on($('#savePasswordBtn'), 'click', async () => {
        const r = await fetch('admin.php', {
            method: 'POST',
            body: new FormData(els.pwdForm)
        }).then(r => r.json());
        showMessage(r.message, r.success);
        if (r.success) closeModals();
    });
    
    on($('#logoutBtn'), 'click', async () => {
        if (confirm('确定要退出登录吗？')) {
            const r = await api('logout');
            if (r.success) location.href = 'login.php';
        }
    });
    
    on($('#cancelBtn'), 'click', closeModals);
    on($('#cancelPasswordBtn'), 'click', closeModals);
    
    els.searchInput.addEventListener(
        'input',
        debounce(() => {
            state.search = els.searchInput.value.trim();
            state.page = 1;
            refresh();
        }, 500)
    );
    
    els.perPageSelects.forEach(s =>
        on(s, 'change', () => {
            state.perPage = parseInt(s.value) || 10;
            state.page = 1;
            refresh();
        })
    );
    
    // 编辑按钮事件委托
    document.addEventListener('click', e => {
        if (e.target.matches('.edit-btn')) {
            openEdit(e.target.dataset.ip, e.target.dataset.desc);
        }
        if (e.target.dataset.page) {
            state.page = parseInt(e.target.dataset.page);
            refresh();
        }
    });
    
    // 点击外部关闭用户菜单
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#userMenuButton') && !e.target.closest('#userMenu')) {
            els.userMenu.classList.remove('show');
        }
    });
    
    /* ===========================
       初始化
    =========================== */
    document.addEventListener('DOMContentLoaded', function() {
        // 初始加载
        refresh();
    
        // 响应式切换
        window.addEventListener('resize', debounce(() => {
            if (els.desktopBody.children.length > 0) {
                const ips = Array.from(els.desktopBody.querySelectorAll('tr:not(#loadingRow)')).map(tr => {
                    const cells = tr.querySelectorAll('td');
                    return {
                        description: cells[0]?.textContent || '',
                        ip: cells[1]?.textContent || '',
                        created_at: cells[2]?.textContent || '',
                        updated_at: cells[3]?.textContent || ''
                    };
                }).filter(ip => ip.ip);
    
                if (ips.length > 0) {
                    renderList(ips);
                }
            }
        }, 250));
    });
    
    // 添加键盘快捷键：Ctrl+R 刷新
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
            e.preventDefault();
            refresh();
        }
    });
    </script>
</body>
</html>
        <?php
    }
}

// 使用示例
try {
    $adminManager = new IPAdminManager();
    $adminManager->handleRequest();
} catch (Exception $e) {
    if (!empty($_POST)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => '系统错误：' . $e->getMessage(),
            'data' => ['ips' => []]
        ]);
        exit;
    }
    
    die('系统错误：' . $e->getMessage());
}
?>