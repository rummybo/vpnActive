<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\library\Sms as Smslib;
use app\common\model\User;
use think\Cache;
use think\Hook;

/**
 * 手机短信接口
 */
class Sms extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    /**
     * 发送验证码
     *
     * @ApiMethod (POST)
     * @ApiParams (name="mobile", type="string", required=true, description="手机号")
     * @ApiParams (name="event", type="string", required=true, description="事件名称")
     */
    public function send()
    {
        $mobile = $this->request->post("mobile");
        $event  = $this->request->post("event");
        $event  = $event ? $event : 'register';

        if (!$mobile || !\think\Validate::regex($mobile, "^1\d{10}$")) {
            $this->error(__('手机号不正确'));
        }
        $last = Smslib::get($mobile, $event);
        if ($last && time() - $last['createtime'] < 60) {
            $this->error(__('发送频繁'));
        }
        $ipSendTotal = \app\common\model\Sms::where(['ip' => $this->request->ip()])->whereTime('createtime', '-1 hours')->count();
        if ($ipSendTotal >= 5) {
            $this->error(__('发送频繁'));
        }
        if ($event) {
            $userinfo = User::getByMobile($mobile);
            if ($event == 'register' && $userinfo) {
                //已被注册
                $this->error(__('已被注册'));
            } elseif (in_array($event, ['changemobile']) && $userinfo) {
                //被占用
                $this->error(__('已被占用'));
            } elseif (in_array($event, ['changepwd', 'resetpwd']) && !$userinfo) {
                //未注册
                $this->error(__('未注册'));
            }
        }
        if (!Hook::get('sms_send')) {
            $this->error(__('请在后台插件管理安装短信验证插件'));
        }
        $ret = Smslib::send($mobile, null, $event);
        if ($ret) {
            $this->success(__('发送成功'));
        } else {
            $this->error(__('发送失败，请检查短信配置是否正确'));
        }
    }

    /**
     * 检测验证码
     *
     * @ApiMethod (POST)
     * @ApiParams (name="mobile", type="string", required=true, description="手机号")
     * @ApiParams (name="event", type="string", required=true, description="事件名称")
     * @ApiParams (name="captcha", type="string", required=true, description="验证码")
     */
    public function check()
    {
        $mobile  = $this->request->post("mobile");
        $event   = $this->request->post("event");
        $event   = $event ? $event : 'register';
        $captcha = $this->request->post("captcha");

        if (!$mobile || !\think\Validate::regex($mobile, "^1\d{10}$")) {
            $this->error(__('手机号不正确'));
        }
        if ($event) {
            $userinfo = User::getByMobile($mobile);
            if ($event == 'register' && $userinfo) {
                //已被注册
                $this->error(__('已被注册'));
            } elseif (in_array($event, ['changemobile']) && $userinfo) {
                //被占用
                $this->error(__('已被占用'));
            } elseif (in_array($event, ['changepwd', 'resetpwd']) && !$userinfo) {
                //未注册
                $this->error(__('未注册'));
            }
        }
        $ret = Smslib::check($mobile, $captcha, $event);
        if ($ret) {
            $this->success(__('成功'));
        } else {
            $this->error(__('验证码不正确'));
        }
    }


    /**
     * 发送短信接口
     * POST /api/sms/send
     * 参数：
     * mobile: 手机号
     */
    public function send_huiyuan(): \think\response\Json
    {
        $mobile = $this->request->param('mobile');
        if (!$mobile) {
            return json([
                            'code' => 0,
                            'msg'  => '缺少必要参数 mobile 或 content'
                        ]);
        }

        // 生成 6 位随机验证码
        $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // 保存到 Redis, 1分钟过期
        Cache::set('sms_code:' . $mobile, $code, 60);

        $smstype = 'notify';
        $content = sprintf('您的验证码：%s，如非本人操作，请忽略本短信!', $code);
        $encode  = "utf-8";
        $user    = 'hai96690BBB';
        $hash    = '63eb537a1c75d14b29651dde73626ff8';
        $url     = "http://www.huiyuandx.com/api/sms_send?";
        $url     .= "user=$user&hash=$hash&encode=$encode&smstype=$smstype";
        $url     .= "&mobile=" . $mobile . "&content=" . $content;

        $ctx = stream_context_create([
             'http' => [
                 'timeout' => 30,
                 'header'  => "User-Agent:Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1;YNSMS API v1.0;)"
             ]
         ]);

        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) {
            return json(['code' => 0, 'msg'  => '请求发送短信接口失败']);
        }

        $rs = json_decode($result, true);
        if (!$rs['result']) {
            return json(['code' => 0, 'msg'  => '发送失败，错误代码: ' . $rs['errcode'] . '，信息: ' . $rs['msg']]);
        }
        return json(['code' => 1, 'msg'  => '短信发送成功', 'data' => $rs]);
    }

    /**
     * 验证验证码接口
     * POST /api/sms/verifyCode
     * 参数：
     * mobile: 手机号
     * code: 验证码
     */
    public function verifyCode(): \think\response\Json
    {
        $mobile = $this->request->param('mobile', '');
        $code   = $this->request->param('code', '');

        if (!$mobile || !$code) {
            return json(['code' => 0, 'msg' => '手机号或验证码不能为空']);
        }

        $cacheCode = Cache::get('sms_code:' . $mobile);
        if (!$cacheCode) {
            return json(['code' => 0, 'msg' => '验证码已过期']);
        }

        if ($cacheCode !== $code) {
            return json(['code' => 0, 'msg' => '验证码错误']);
        }

        // 验证成功后删除缓存
        Cache::rm('sms_code:' . $mobile);

        return json(['code' => 1, 'msg' => '验证码验证成功']);
    }
}
