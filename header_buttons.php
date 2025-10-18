<?php
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isDesktop = (bool)preg_match('/Windows NT|Macintosh|X11; Linux/i', $userAgent);
?>

<style>
/* 下拉菜单样式 */
.dropdown {
  position: relative;
  display: inline-block;
}
.dropdown-toggle {
  cursor: pointer;
}
.dropdown-menu {
  display: none;
  position: absolute;
  left: 0;
  top: 100%;
  min-width: 160px;
  z-index: 1000;
  background: #fff;
  border: 1px solid #ddd;
  border-radius: 0.375rem;
  padding: 0.5rem 0;
  box-shadow: 0 6px 18px rgba(0,0,0,.06);
}
.dropdown:hover .dropdown-menu,
.dropdown:focus-within .dropdown-menu {
  display: block;
}
.dropdown-menu a {
  display: block;
  padding: 0.375rem 0.75rem;
  color: #333;
  text-decoration: none;
  white-space: nowrap;
}
.dropdown-menu a:hover {
  background: #f0f0f0;
}
</style>

<div class="btn-list">
  <a href="add_item.php" class="btn btn-primary btn-sm">➕ 添加衣物</a>
  <a href="clothes_search.php" class="btn btn-outline-primary btn-sm">🔍 筛选</a>
  <a href="outfits_create.php" class="btn btn-outline-primary btn-sm">创建穿搭</a>
  <a href="outfits_index.php" class="btn btn-outline-primary btn-sm">查看穿搭</a>
  <a href="statistics.php" class="btn btn-outline-primary btn-sm">📊 价格统计</a>

  <!-- 管理功能下拉菜单 -->
  <div class="dropdown">
    <span class="btn btn-outline-primary btn-sm dropdown-toggle" tabindex="0">⚙️ 管理功能</span>
    <div class="dropdown-menu" role="menu" aria-label="管理功能">
      <a href="manage_categories.php">📂 分类管理</a>
      <a href="manage_sources.php">🛍️ 购买途径管理</a>
      <a href="manage_tags.php">🏷️ 标签管理</a>
      <a href="seasons_manage.php">🌞 季节管理</a>
    </div>
  </div>

  <!-- 批量修改下拉菜单 -->
  <div class="dropdown">
    <span class="btn btn-outline-primary btn-sm dropdown-toggle" tabindex="0">🛠️ 批量修改</span>
    <div class="dropdown-menu" role="menu" aria-label="批量修改">
      <a href="clothes_batch_edit.php">🌞 批量编辑季节</a>
      <a href="clothes_batch_name.php">✏️ 批量编辑名称</a>
    </div>
  </div>

  <!-- 邮件设置下拉菜单（新增） -->
  <div class="dropdown">
    <span class="btn btn-outline-primary btn-sm dropdown-toggle" tabindex="0">✉️ 邮件设置</span>
    <div class="dropdown-menu" role="menu" aria-label="邮件设置">
      <a href="solar_terms_manage.php">📅 节气设置</a>
      <a href="email_settings.php">⚙️ 邮件配置</a>
      <a href="token_settings.php">🔐 token设置</a>
    </div>
  </div>

  <?php if ($isDesktop): ?>
    <!-- 用户操作下拉菜单 -->
    <div class="dropdown">
      <span class="btn btn-outline-secondary btn-sm dropdown-toggle" tabindex="0">👤 用户操作</span>
      <div class="dropdown-menu" role="menu" aria-label="用户操作">
        <a href="token_manage.php">💻 设备管理</a>
        <a href="delete_thumbs.php">🗑️ 清理缓存</a>
        <a href="logout.php" style="color: red;">🚪 退出登录</a>
      </div>
    </div>
  <?php endif; ?>
</div>
