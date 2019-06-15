<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 防机器人自动评论插件，普通用户无感知；
 * 利用访问者计算量证明实现验证，对针对站点定制的恶意机器人，防御作用有限。
 *
 * @package SimpleCommentCaptcha
 * @author  Huspy
 * @version 1.0.0
 * @link https://www.mierhuo.com
 */
class SimpleCommentCaptcha_Plugin implements Typecho_Plugin_Interface
{
    protected static $session_key_prefix = 'SimpleCommentCaptcha_Plugin_';
    protected static $form_field_name = 'SimpleCommentCaptcha_Plugin_x';
    protected static $calculation_param_x;
    protected static $calculation_param_y;
    protected static $calculation_res;
    protected static $calculation_param_x_start;
    protected static $calculation_param_x_end;

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @throws Typecho_Db_Exception
     */
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Feedback')->comment = array(
            'SimpleCommentCaptcha_Plugin',
            'beforeComment'
        );
        Typecho_Plugin::factory('Widget_Archive')->beforeRender = array(
            'SimpleCommentCaptcha_Plugin',
            'initCaptcha'
        );
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @access public
     * @return void
     */
    public static function deactivate(){}

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form){
        $protection_level = new Typecho_Widget_Helper_Form_Element_Radio(
            'protection_level',
            array(
                '1' => '轻微',
                '10' => '普通',
                '100' => '中等',
                '1000' => '特别',
            ),
            '1',
            _t('保护力度等级'),
            _t('等级越高，假想敌手所需运算量越多，但对普通用户也会造成影响，适当即可')
        );
        $delay_time = new Typecho_Widget_Helper_Form_Element_Text(
            'delay_time',
            null,
            '3',
            _t('访问页面到提交评论完成最少等待时间（单位为秒，不宜过大）'),
            _t('受限于资源加载情况多变，实现上仅延迟前端验证步骤')
        );
        $banned_action = new Typecho_Widget_Helper_Form_Element_Radio(
            'banned_action',
            array(
                '0' => '进入待审核',
                '1' => '进入垃圾箱',
                '2' => '禁止评论',
            ),
            '1',
            _t('验证失败的留言处理方式'),
            _t('')
        );
        $spam_ip_action = new Typecho_Widget_Helper_Form_Element_Radio(
            'spam_ip_action',
            array(
                '0' => '进入待审核',
                '1' => '进入垃圾箱',
                '2' => '不处理',
            ),
            '0',
            _t('垃圾评论源 IP 再次评论'),
            _t('')
        );
        $banned_ip_list = new Typecho_Widget_Helper_Form_Element_Textarea(
            'banned_ip_list',
            NULL,
            '',
            _t('禁止评论 IP 黑名单（每行一个）')
        );
        $alert_message = new Typecho_Widget_Helper_Form_Element_Radio(
            'alert_message',
            array(
                '0' => '关闭',
                '1' => '开启',
            ),
            '0',
            _t('评论进入待审核状态时 alert 提醒读者'),
            _t('仅需在您所使用的主题不支持回显审核状态的评论时开启（一般都支持）')
        );
        $form->addInput($protection_level);
        $form->addInput($delay_time);
        $form->addInput($banned_action);
        $form->addInput($spam_ip_action);
        $form->addInput($banned_ip_list);
        $form->addInput($alert_message);
    }

    /**
     * 评论数据入库前校验
     *
     * @param array $comment
     * @param Widget_Archive $archive
     * @return void
     */
    public static function beforeComment($comment, $archive) {
        $submit_x = (int) $archive->request->get(self::$form_field_name);
        $banned_ip_list = array_map(
            'trim',
            explode("\n", self::getFromOptions('banned_ip_list'))
        );

        if (in_array($comment['ip'], $banned_ip_list)) {
            // ip 在黑名单内
            throw new Typecho_Widget_Exception(_t('您所在 ip 已被禁止评论操作！'));
        }

        if (
            $submit_x
            && self::getFromSession('x')
            && self::getFromSession('x') === $submit_x
        ) {
            // 校验成功
            self::removeFromSession('x');

            $spam_ip_action = self::getFromOptions('spam_ip_action');
            if ($spam_ip_action === '2') {
                // 若不处理存在垃圾评论的 ip ，直接返回
                return $comment;
            }

            if (in_array($comment['ip'], self::getCommentedTrashIpList())) {
                // ip 存在垃圾留言记录
                switch ($spam_ip_action) {
                    case '0':
                        $comment['status'] = 'waiting';
                        self::storeInSession(
                            'message',
                            _t('您的留言已进入待审核列表！')
                        );
                        break;
                    case '1':
                        $comment['status'] = 'spam';
                        break;
                }
            }
        } else {
            // 校验失败
            switch (self::getFromOptions('banned_action')) {
                case '0':
                    $comment['status'] = 'waiting';
                    self::storeInSession(
                        'message',
                        _t('您的留言已进入待审核列表！')
                    );
                    break;
                case '1':
                    $comment['status'] = 'spam';
                    break;
                case '2':
                    throw new Typecho_Widget_Exception(_t('您的评论操作不合法！'));
            }
        }

        return $comment;
    }

    /**
     * 验证数据初始化、存储会话数据
     *
     * @param Widget_Archive $archive
     * @return void
     */
    public static function initCaptcha($archive)
    {
        // 只在文章页和独立页面初始化验证数据（其他地方没有评论）
        if (!$archive->is('single') && !$archive->is('page')) {
            return ;
        }

        $start_num = 1000 * self::getFromOptions('protection_level');
        $end_num = $start_num * 10 - 1;
        self::$calculation_param_x_start = $start_num;
        self::$calculation_param_x_end = $end_num;
        self::$calculation_param_x = rand($start_num, $end_num);
        self::$calculation_param_y = rand(1, 1000) + 1;
        self::$calculation_res = self::calculateFunction(
            self::$calculation_param_x,
            self::$calculation_param_y
        );

        self::storeInSession('x', self::$calculation_param_x);
        self::storeInSession('y', self::$calculation_param_y);
        self::storeInSession('res', self::$calculation_res);
    }

    /**
     * 输出验证码所需表单内容和运算数据、过程
     * 需要在主题的评论区域（表单内部）调用
     *
     * @return void
     */
    public static function outputSimpleCommentCaptchaField()
    {
        $message = self::getFromSession('message');
        $message = $message ? $message : '';

        if (self::getFromOptions('alert_message') === '0') {
            // 若关闭待审核 alert 提醒
            $message = '';
        }

        echo '<input name="' . self::$form_field_name . '" value="" hidden>';
        echo '<script> document.addEventListener("readystatechange",function(){'
            . '  if (document.readyState !== "interactive") {return;}'
            . '  setTimeout(function() {'
            . '    var SCCF_startX = ' . self::$calculation_param_x_start . ';'
            . '    var SCCF_endX = ' . self::$calculation_param_x_end .';'
            . '    var SCCF_res = ' . self::$calculation_res . ';'
            . '    var SCCF_paramY= ' . self::$calculation_param_y. ';'
            . '    var SCCF_tmpRes = 0;'
            . '    var SCCF_midM = 0;'
            . '    for (var i = SCCF_startX; i <= SCCF_endX; i++) {'
            . '      SCCF_midM = Math.abs('
            . '        Math.sin((i % Math.PI) * ((i + 2) % Math.PI) * 120)'
            . '      ) * 111;'
            . '      SCCF_midM *= 10000000;'
            . '      SCCF_tmpRes = Math.log(SCCF_midM) / Math.log(SCCF_paramY);'
            . '      if (Math.abs(SCCF_tmpRes - SCCF_res) < 0.0000000001) {'
            . '        var inputElement = document.getElementsByName("'
            .            self::$form_field_name . '")[0];'
            . '        inputElement.value = i;'
            . '        break;'
            . '      }'
            . '    }'
            . '  }, ' . self::getFromOptions('delay_time') * 1000 . ');'
            . '  var SCCF_message = "' . $message . '";'
            . '  if (SCCF_message) {alert(SCCF_message);}'
            . '});</script>';
        self::removeFromSession('message');
    }

    /**
     * 计算算子
     *
     * @param int $x 因子 x
     * @param int $y 因子 y
     * @return double 计算结果
     */
    public static function calculateFunction($x, $y)
    {
        $mid_num = abs(sin(fmod($x, pi()) * fmod($x + 2, pi()) * 120)) * 111;
        self::storeInSession('x', $x);
        return log($mid_num * 10000000, $y);
    }

    /**
     * 存储一项数据到会话
     *
     * @param string $name 会话数据项名称
     * @param mixed $value 会话数据项值
     * @return void
     */
    public static function storeInSession($name, $value)
    {
        self::ensureSessionStarted();
        $_SESSION[self::$session_key_prefix . $name] = $value;
    }

    /**
     * 从会话中获取一项数据
     *
     * @param string $name
     * @param mixed $default
     * @return void
     */
    public static function getFromSession($name, $default = null)
    {
        self::ensureSessionStarted();
        $key = self::$session_key_prefix . $name;
        if (isset($_SESSION[$key])) {
            return $_SESSION[$key];
        }
        return $default;
    }

    /**
     * 从会话中删除一项数据
     *
     * @param string $name 会话数据项名称
     * @return void
     */
    public static function removeFromSession($name)
    {
        self::ensureSessionStarted();
        unset($_SESSION[self::$session_key_prefix . $name]);
    }

    /**
     * 确保 session 开启
     *
     * @return void
     */
    public static function ensureSessionStarted()
    {
        if (!session_id()) {
            session_start();
        }
    }

    /**
     * 获取插件设置结果
     *
     * @param string $name 设置项名称
     * @return string
     */
    public static function getFromOptions($name)
    {
        return Typecho_Widget::widget('Widget_Options')
            ->plugin('SimpleCommentCaptcha')->{$name};
    }

    /**
     * 获取存在垃圾评论的 ip 列表
     *
     * @return array
     */
    public static function getCommentedTrashIpList()
    {
        $db = Typecho_Db::get();
        $ip_list = $db->fetchAll(
            $db->select('ip')->from('table.comments')
                ->where('status = ?', 'spam')
        );
        return array_column($ip_list, 'ip');
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}
}
