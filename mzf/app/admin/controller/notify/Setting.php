<?php

namespace app\admin\controller\notify;

use Throwable;
use app\common\library\Email;
use PHPMailer\PHPMailer\PHPMailer;
use app\common\controller\Backend;
use app\admin\model\Config as ConfigModel;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * 通知设置：邮件(SMTP) + WxPusher appToken + 后台新用户注册提醒 开关/收件人
 * 数据存于 ba_config（部分沿用原邮件分组 group=mail，供 Email 读取）。
 */
class Setting extends Backend
{
    protected object $model;

    // 本页管理的配置项
    protected array $fields = [
        'smtp_server', 'smtp_user', 'smtp_pass', 'smtp_verification', 'smtp_port', 'smtp_sender_mail',
        'mail_pool',
        'wxpusher_apptoken',
        'notify_admin_email', 'notify_admin_wxpush_uid',
        'notify_admin_register_email', 'notify_admin_register_wxpush',
    ];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new ConfigModel();
    }

    public function index(): void
    {
        $rows = $this->model->whereIn('name', $this->fields)->select();
        $data = [];
        foreach ($rows as $row) {
            // 取原始存储值：switch 类型的访问器会转成 bool，导致前端 active-value="1" 回显错位
            $data[$row['name']] = $row->getData('value');
        }
        // 补齐缺省
        foreach ($this->fields as $f) {
            if (!array_key_exists($f, $data)) $data[$f] = '';
        }
        $this->success('', ['data' => $data]);
    }

    public function save(): void
    {
        if (!$this->request->isPost()) $this->error('参数错误');
        $post = $this->request->post();

        $rows = $this->model->whereIn('name', $this->fields)->select();
        $save = [];
        foreach ($rows as $row) {
            if (array_key_exists($row['name'], $post)) {
                $save[] = ['id' => $row['id'], 'type' => $row->getData('type'), 'value' => $post[$row['name']]];
            }
        }
        if (!$save) $this->error('无可保存项');

        $this->model->startTrans();
        try {
            $this->model->saveAll($save);
            $this->model->commit();
        } catch (Throwable $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }
        $this->success('保存成功');
    }

    public function testMail(): void
    {
        $data = $this->request->post();
        $mail = new Email();
        try {
            $mail->Host       = $data['smtp_server'] ?? get_sys_config('smtp_server');
            $mail->SMTPAuth   = true;
            $mail->Username   = $data['smtp_user'] ?? '';
            $mail->Password   = $data['smtp_pass'] ?? '';
            $mail->SMTPSecure = ($data['smtp_verification'] ?? 'SSL') == 'SSL' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $data['smtp_port'] ?? 465;
            $mail->setFrom($data['smtp_sender_mail'] ?? $data['smtp_user'], $data['smtp_user'] ?? '');
            $mail->isSMTP();
            $mail->addAddress($data['testMail']);
            $mail->isHTML();
            $mail->setSubject('这是一封测试邮件-' . get_sys_config('site_name'));
            $mail->Body = '恭喜，收到本邮件说明你的邮件服务已配置正确。';
            $mail->send();
        } catch (PHPMailerException) {
            $this->error($mail->ErrorInfo);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('测试邮件发送成功~');
    }
}
