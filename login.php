<?php
// login.php
require_once 'AuthService.php';

class LoginManager
{
    private $authService;
    private $dbFile = 'SQLite.db';
    
    public function __construct()
    {
        $this->authService = new AuthService($this->dbFile);
        $this->handleLogin();
    }
    
    private function handleLogin()
    {
        // 启动会话
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        
        // 检查是否已经登录，如果已登录则重定向到admin.php
        if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
            header('Location: admin.php');
            exit;
        }
        
        // 处理登录表单提交
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
            $this->processLogin();
            return;
        }
        
        // 显示登录页面
        $this->showLoginForm();
    }
    
    private function processLogin()
    {
        // 验证CSRF token
        $token = $_POST['form_token'] ?? '';
        if (empty($_SESSION['login_form_token']) || !hash_equals($_SESSION['login_form_token'], $token)) {
            $_SESSION['login_error'] = '请勿重复提交或页面已过期，请刷新后重试。';
            header('Location: login.php');
            exit;
        }
        
        // 验证验证码
        $captchaInput = trim($_POST['captcha'] ?? '');
        $sessionCaptcha = $_SESSION['captcha'] ?? '';
        
        // 简单直接的验证码验证
        if (empty($captchaInput)) {
            $_SESSION['login_error'] = '验证码不能为空！';
            unset($_SESSION['captcha']);
            header('Location: login.php');
            exit;
        }
        
        if ($captchaInput !== $sessionCaptcha) {
            $_SESSION['login_error'] = '验证码错误！';
            unset($_SESSION['captcha']);
            header('Location: login.php');
            exit;
        }
        
        // token 用完即销毁，防止重复点击/重复提交
        unset($_SESSION['login_form_token']);
        unset($_SESSION['captcha']); // 验证通过后清除验证码
        
        // 验证密码
        if ($this->authenticate($_POST['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['login_time'] = time();
            header('Location: admin.php');
            exit;
        }
        
        $_SESSION['login_error'] = '密码错误！';
        header('Location: login.php');
        exit;
    }
    
    private function authenticate($password)
    {
        $adminPassword = $this->authService->getAdminPassword();
        return password_verify($password, $adminPassword);
    }
    
    private function showLoginForm()
    {
        // 生成新的CSRF token
        if (empty($_SESSION['login_form_token'])) {
            $_SESSION['login_form_token'] = bin2hex(random_bytes(16));
        }
        
        $formToken = $_SESSION['login_form_token'];
        
        // 从session获取错误信息并清除
        $error = $_SESSION['login_error'] ?? '';
        unset($_SESSION['login_error']);
        
        ?>
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>IP授权管理 - 登录</title>
            <script src="https://cdn.tailwindcss.com"></script>
            <style>
                .gradient-bg {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                }
                .login-card {
                    backdrop-filter: blur(10px);
                    background: rgba(255, 255, 255, 0.95);
                }
                .captcha-input {
                    font-size: 16px; /* 防止iOS自动放大 */
                }
                /* 验证码图片基础样式（移动端） */
                .captcha-img {
                    border: 1px solid #e5e7eb;
                    border-radius: 0.375rem;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    height: 38px;           /* 移动端保持原高度 */
                    width: auto;
                }
                
                /* PC 端精确对齐输入框 */
                @media (min-width: 768px) {
                    .captcha-wrapper {
                        display: flex;
                        align-items: center; /* 垂直居中 */
                    }
                
                    .captcha-img {
                        height: 48px;        /* = md 输入框高度 */
                        object-fit: contain;
                    }
                }
            </style>
        </head>
        <body class="gradient-bg min-h-screen flex items-center justify-center p-4">
            <div class="w-full max-w-md">
                <div class="login-card rounded-2xl shadow-2xl p-6 md:p-8 border border-white/20">
                    <div class="text-center mb-6 md:mb-8">
                        <div class="w-12 h-12 md:w-16 md:h-16 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center mx-auto mb-3 md:mb-4">
                            <svg class="w-6 h-6 md:w-8 md:h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                        </div>
                        <h1 class="text-xl md:text-2xl font-bold text-gray-800">IP授权管理</h1>
                        <p class="text-gray-600 mt-1 md:mt-2 text-sm md:text-base">请使用管理员密码登录</p>
                    </div>
                    
                    <?php if ($error): ?>
                        <div class="bg-red-50 border border-red-200 text-red-700 px-3 py-2 md:px-4 md:py-3 rounded-lg mb-4 text-sm">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" class="space-y-4 md:space-y-6" id="loginForm">
                        <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($formToken, ENT_QUOTES, 'UTF-8'); ?>">
                        
                        <!-- 密码输入 -->
                        <div>
                            <div class="relative">
                                <input type="password" id="password" name="password" required 
                                    class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm md:text-base"
                                    placeholder="请输入管理员密码"
                                    autocomplete="current-password">
                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                    <svg class="h-4 w-4 md:h-5 md:w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                    </svg>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 验证码输入 -->
                        <div>
                            <div class="flex space-x-2">
                                <div class="flex-grow">
                                    <input type="text" id="captcha" name="captcha" required 
                                        class="w-full px-3 py-2 md:px-4 md:py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm md:text-base"
                                        placeholder="请输入验证码"
                                        autocomplete="off"
                                        maxlength="4">
                                </div>
                                <div class="flex-shrink-0">
                                    <img src="captcha.php?t=<?php echo time(); ?>" 
                                         alt="验证码" 
                                         class="captcha-img"
                                         onclick="this.src='captcha.php?t=' + Date.now()"
                                         title="点击刷新验证码"
                                         id="captchaImage">
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="w-full bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 text-white font-medium py-2 md:py-3 px-4 rounded-lg transition-all duration-200 transform hover:scale-[1.02] focus:scale-[0.98] text-sm md:text-base">
                            登录系统
                        </button>
                    </form>
                    
                    <div class="mt-3 text-center text-xs text-gray-500">
                        <p>© <?php echo date('Y'); ?> IP授权管理系统</p>
                    </div>
                </div>
            </div>
            
            <script>
                function refreshCaptcha() {
                    const captchaImg = document.getElementById('captchaImage');
                    if (captchaImg) {
                        // 添加时间戳防止缓存
                        captchaImg.src = 'captcha.php?t=' + Date.now();
                        // 清空验证码输入框
                        const captchaInput = document.getElementById('captcha');
                        if (captchaInput) {
                            captchaInput.value = '';
                            captchaInput.focus();
                        }
                    }
                }
                
                // 自动聚焦到密码输入框
                document.addEventListener('DOMContentLoaded', function() {
                    const passwordInput = document.getElementById('password');
                    if (passwordInput) {
                        passwordInput.focus();
                    }
                    
                    // 页面加载时如果验证码图片加载失败，自动重试
                    const captchaImg = document.getElementById('captchaImage');
                    if (captchaImg) {
                        captchaImg.onerror = function() {
                            console.log('验证码图片加载失败，正在重试...');
                            refreshCaptcha();
                        };
                    }
                    
                    // 表单提交验证
                    const loginForm = document.getElementById('loginForm');
                    if (loginForm) {
                        loginForm.addEventListener('submit', function(e) {
                            const captcha = document.getElementById('captcha').value.trim();
                            if (captcha.length !== 4) {
                                e.preventDefault();
                                alert('验证码必须是4位字符');
                                document.getElementById('captcha').focus();
                                return false;
                            }
                            
                            const password = document.getElementById('password').value.trim();
                            if (password.length === 0) {
                                e.preventDefault();
                                alert('请输入密码');
                                document.getElementById('password').focus();
                                return false;
                            }
                            
                            return true;
                        });
                    }
                });
                
                // 键盘快捷键：F5刷新验证码
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'F5') {
                        e.preventDefault();
                        refreshCaptcha();
                    }
                });
            </script>
        </body>
        </html>
        <?php
    }
}

// 使用登录管理器
try {
    $loginManager = new LoginManager();
} catch (Exception $e) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>系统错误</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gray-100 min-h-screen flex items-center justify-center">
        <div class="bg-white p-8 rounded-lg shadow-md max-w-md">
            <div class="text-center">
                <svg class="w-16 h-16 text-red-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <h2 class="text-xl font-bold text-gray-800 mb-2">系统错误</h2>
                <p class="text-gray-600 mb-4"><?php echo htmlspecialchars($e->getMessage()); ?></p>
                <a href="login.php" class="inline-block px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                    返回登录页面
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>