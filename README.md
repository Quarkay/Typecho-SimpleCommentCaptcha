Typecho-SimpleCommentCaptcha
----

## 简介

Typecho-SimpleCommentCaptcha 插件用于防御全网自动扫描可评论网址并自动提交评论的机器人，普通用户无感知（利用访问者计算量证明实现验证，对针对站点定制的恶意机器人，防御作用有限），且无需任何第三方服务配置。

如果你遇到了此类机器人的困扰，不想使用传统验证码，同时又不想配置类似滑动验证码的所谓“现代”验证码，那么这款插件是合适的。如果遇到了针对站点定制化的恶意自动评论机器人，此插件防御作用有限，但能有效提升攻击成本，请根据情况合理使用。

同时，插件支持评论操作 IP 黑名单、垃圾评论源 IP 再次评论如何处理的设置。

## 安装

1. 首先将本项目克隆到本地：

    ```bash
    git clone git@github.com:mierhuo/Typecho-SimpleCommentCaptch.git
    ```

2. 将子文件夹 SimpleCommentCaptcha 复制到 Typecho 插件目录

    ```bash
    cp -r Typecho-SimpleCommentCaptcha/SimpleCommentCaptcha /path...to...your...typecho/usr/plugins/
    ```
    
3. 在Typecho后台点击启用并进行相关设置如下图：

    <img src="https://raw.githubusercontent.com/mierhuo/Typecho-SimpleCommentCaptcha/master/config_example.png" alt="配置设置例子">

4. 在所使用的主题评论表单区域，如下例中的提交评论按钮前，添加一行代码：

    ```html
    <p> <!-- example-context -->
        <?php SimpleCommentCaptcha_Plugin::outputSimpleCommentCaptchaField(); ?> <!-- 添加此行 -->
        <button type="submit" class="submit"><?php _e('提交评论'); ?></button> <!-- example-context -->
    </p> <!-- example-context -->
    ```

## License

<a href="https://github.com/mierhuo/Typecho-SimpleCDN/blob/master/LICENSE.txt">The GNU General Public License (GPL) V2</a>
