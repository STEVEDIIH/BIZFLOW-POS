<header class="topbar">
    <div class="business-name">
        <?php echo htmlspecialchars($_SESSION['business_name'] ?? 'BizFlow'); ?>
    </div>

    <div class="user-info">
        <div class="user-details">
            <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?>
            <div class="user-role">
                <?php echo htmlspecialchars($_SESSION['role_name'] ?? 'Admin'); ?>
            </div>
        </div>

        <!-- Logout Button -->
        <a href="/BIZFLOW/modules/auth/logout.php" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            Logout
        </a>
    </div>
</header>