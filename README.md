# 👕 Wear —— PHP + MySQL 服装管理系统

一个基于 **PHP + MySQL** 开发的轻量级服装管理系统，用于记录、分类和展示你的衣物。  
采用 Tabler UI 设计，界面简洁，支持后台管理与安装向导，适合个人或小型服装档案管理使用。
<img width="1321" height="656" alt="Snipaste_2025-10-18_22-28-08" src="https://github.com/user-attachments/assets/24a09726-8818-430e-be56-0301379da87f" />

---

## 🌟 功能特色

- **分类与子分类管理**  
  支持上衣、下装、连衣裙、鞋、包等主分类，并可添加二级分类（如衬衫、毛衣、外套等）。
<img width="1407" height="880" alt="Snipaste_2025-10-18_22-31-08" src="https://github.com/user-attachments/assets/802ff349-62e6-4a47-aa91-c0f453faa90d" />

- **衣物详细记录**  
  每件衣服可记录以下信息：  
  
  - 名称  
  - 分类与子分类  
  - 适用季节  
  - 收纳位置  
  - 品牌  
  - 价格、尺码、购买途径、购买时间  
  - 标签与备注  
  - 图片（可选上传）
<img width="1320" height="896" alt="Snipaste_2025-10-18_22-28-26" src="https://github.com/user-attachments/assets/1debf5d7-5315-4009-afad-8828dca9376f" />

- **标签管理**  
  可自定义标签，例如“旅行”、“通勤”、“夏季搭配”等。

- **购买途径管理**  
  
  维护常用购买渠道（商场、网店、品牌专柜等）。

- **穿搭组合功能**
  
  首页顶部按钮：创建穿搭，可将多件不同类型衣服搭配成一套造型，方便记录与展示。
  <img width="1368" height="911" alt="Snipaste_2025-10-18_22-29-50" src="https://github.com/user-attachments/assets/53859970-7deb-4adf-a117-ebf35b0b3c42" />

  首页顶部按钮：查看穿搭，以卡片形式展示每套搭配内容，可根据季节和穿搭名称筛选。支持修改、删除已经创建的穿搭组合。
<img width="1361" height="628" alt="Snipaste_2025-10-18_22-30-32" src="https://github.com/user-attachments/assets/aa5f9594-0bd9-46c8-9a27-fe509d573c21" />

- **用户登录验证**  
  单用户账号密码登录，未登录用户自动跳转至登录页面。

- **页面布局**  
  
  页面优先适配 PC 端，移动端使用自适应布局。

- **穿衣推荐邮件通知**
  
  `域名或ip/solar_terms_manage.php`
  
  （首页顶部菜单——邮件设置——节气设置）
  
  该页面用于管理系统中的 **二十四节气** 数据，邮件发送适合当前季节的穿衣推荐基于二十四节气信息配置。二十四节气每个节气单独设置日期和适合的服装对应季节，初次安装后若需要邮件推送穿衣推荐则需要配置二十四节气信息。节气的日期采用数据库写死固定日期方式，因为穿衣推荐没必要实现天文学精度，二十四节气每年相差误差仅一两天，足够穿衣推荐使用。
  <img width="1145" height="894" alt="Snipaste_2025-10-18_22-32-51" src="https://github.com/user-attachments/assets/35c86172-bc82-4b55-9cac-80bb5c68c1fe" />

  `域名或ip/email_settings.php`
  
  （首页顶部菜单——邮件设置——邮件配置）
  
  用于设置发件邮箱和收件人信息。
  <img width="1050" height="862" alt="Snipaste_2025-10-18_22-33-04" src="https://github.com/user-attachments/assets/7b369334-9b1d-4239-b1ab-13e88238ca06" />

  `域名或ip/token_settings.php`
  
  （首页顶部菜单——邮件设置——token设置）
  
  通过生成 Token，可以让外部访问链接具备访问权限控制。使用链接时，在访问地址后添加 `?token=你的Token` 参数即可访问受保护的页面。
  <img width="1322" height="619" alt="Snipaste_2025-10-18_22-33-23" src="https://github.com/user-attachments/assets/a534f176-aebd-4792-8680-834d657a6040" />
  例如：域名或ip/email_send.php?token你的Token


  使用宝塔面板等工具定时任务设置定时访问url即可实现定时发送穿衣推荐电子邮件。

- **安装向导**  
  `/install/install.php` 支持自动导入数据库、生成配置文件 `db.php`，一键完成部署。

- **本地化 Tabler UI**  
  所有 CSS 与 JS 均为本地引用，无需外部 CDN，加载更快。

---

## 📦 安装说明

1. 上传所有文件至服务器（需要 PHP 8.0+ 与 MySQL 环境）；  
2. 在浏览器访问 `域名或ip/install/install.php`；  
3. 填写数据库连接信息与管理员账号；  
4. 安装程序将：  
   - 自动导入 `/install/sql.sql`数据库结构  
   - 生成数据库连接文件 `/db.php`  
   - 创建 `/install/install.lock` 防止重复安装  
5. 安装完成后请删除或限制访问 `/install` 目录。

---

## 🧩 目录结构

```
wear/
├── install/
│   ├── install.php          # 安装向导
│   ├── sql.sql              # 数据库结构
│   └── install.lock         # 安装锁文件
├── style/
│   ├── tabler.min.css       # 本地 Tabler UI 样式
│   └── tabler.min.js        # 本地 Tabler 脚本
├── thumbs/                  # 缩略图缓存目录
├── uploads/                 # 图片上传目录
├── db.php                   # 自动生成的数据库连接文件
├── login.php / logout.php   # 登录与登出页面
└── index.php                # 首页，展示主分类
```

---

## 🧠 技术栈

- **后端：** PHP 8.0+  
- **数据库：** MySQL   
- **前端：** Tabler UI（HTML + CSS + JS）  
- **部署环境：** LAMP / LNMP（Linux + Apache/Nginx + MySQL + PHP）

---

## 🧩 版权与开源说明

本项目以学习与个人使用为目的开源，欢迎二次开发与功能扩展。  
请在衍生项目中保留作者与原始项目链接。

项目使用ChatGPT开发。

---

## 🙌 致谢

- **Tabler UI** – 现代响应式前端框架  
- **PHPMailer** – 邮件发送组件（在提醒模块中使用）  
- **图标** – 使用ChatGPT设计  
