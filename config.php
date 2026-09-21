<?php
/**
 * config.php —— 全项目唯一需要你修改的文件。
 *
 * 改完保存即可，不需要重启任何东西：
 *   - 改 APP_PASSWORD 会让所有已登录的设备立刻退出（要重新输密码）
 *   - 改 APP_SECRET   同上，但它只影响登录状态，和密码本身无关
 */
declare(strict_types=1);

/**
 * 访问密码。只有一个密码，没有用户名。
 *
 * ⚠ 下面是默认值，上线前请务必改掉；没改的话登录页会一直提示你。
 */
const APP_PASSWORD = 'ielts';

/**
 * 签名密钥。浏览器里存的不是密码，而是用它签出来的令牌，
 * 所以别人看到 cookie 也推不出密码。随便改成一串别人猜不到的字符。
 */
const APP_SECRET = 'change-this-to-something-random';
