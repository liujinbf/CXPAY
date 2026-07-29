<?php

namespace app\admin\controller\user;

use Throwable;
use ba\Random;
use think\facade\Db;
use app\common\facade\Token;
use app\common\controller\Backend;
use app\admin\model\User as UserModel;

class User extends Backend
{
    /**
     * @var object
     * @phpstan-var UserModel
     */
    protected object $model;

    protected array $withJoinTable = ['userGroup'];

    // 排除字段
    protected string|array $preExcludeFields = ['last_login_time', 'login_failure', 'password', 'salt', 'packvip_days', 'packvip_time_text', 'packvip_time_input'];

    protected string|array $quickSearchField = ['username', 'nickname', 'id'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new UserModel();
    }

    /**
     * 查看
     * @throws Throwable
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        list($where, $alias, $limit, $order) = $this->queryBuilder();
        $res = $this->model
            ->withoutField('password,salt')
            ->withJoin($this->withJoinTable, $this->withJoinType)
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        // 列表补充：余额(分→元) + 会员套餐名
        $packMap = Db::name('pay_packvip')->column('name', 'id');
        foreach ($res as $re) {
            // money 字段已由模型获取器自动转换为元（bcdiv($value, 100, 2)），无需再次除以 100
            $re->money_yuan   = $re->money;
            $re->packvip_name = $packMap[(int) $re->packvip_id] ?? '';
        }

        $this->success('', [
            'list'   => $res->items(),
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 直接登录到该会员的前台（后台一键代登录）
     * 生成一个前台会员 token，返回自动登录 URL。
     */
    public function directLogin(): void
    {
        $id   = (int) $this->request->param('id');
        $user = UserModel::find($id);
        if (!$user) {
            $this->error('用户不存在');
        }
        if ($user->status !== 'enable') {
            $this->error('该会员已被禁用，无法登录');
        }
        // 直接签发前台 token（type=user，与 app\common\library\Auth::TOKEN_TYPE 一致），不触发登录通知事件
        $token = Random::uuid();
        Token::set($token, 'user', $id, 86400 * 3);
        $url = $this->request->domain() . '/user?fastLogin=' . urlencode($token);
        $this->success('已生成登录链接', ['url' => $url]);
    }

    /**
     * 添加
     * @throws Throwable
     */
    public function add(): void
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            $result = false;
            $passwd = $data['password']; // 密码将被排除不直接入库
            // 会员天数 → 到期时间戳
            if (!empty($data['packvip_days']) && (int) $data['packvip_days'] > 0) {
                $data['packvip_time'] = time() + (int) $data['packvip_days'] * 86400;
            }
            $data   = $this->excludeFields($data);

            $this->model->startTrans();
            try {
                // 模型验证
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate) $validate->scene('add');
                        $validate->check($data);
                    }
                }
                $result = $this->model->save($data);
                $this->model->commit();

                if (!empty($passwd)) {
                    $this->model->resetPassword($this->model->id, $passwd);
                }
            } catch (Throwable $e) {
                $this->model->rollback();
                $this->error($e->getMessage());
            }
            if ($result !== false) {
                $this->success(__('Added successfully'));
            } else {
                $this->error(__('No rows were added'));
            }
        }

        $this->error(__('Parameter error'));
    }

    /**
     * 编辑
     * @throws Throwable
     */
    public function edit(): void
    {
        $pk  = $this->model->getPk();
        $id  = $this->request->param($pk);
        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if ($this->request->isPost()) {
            $password = $this->request->post('password', '');
            if ($password) {
                $this->model->resetPassword($id, $password);
            }

            // 优先使用直接设置的到期时间（packvip_time_input）
            $timeInput = $this->request->post('packvip_time_input', '');
            if ($timeInput) {
                // 如果提供了到期时间，转为时间戳
                $timestamp = strtotime($timeInput);
                if ($timestamp !== false) {
                    $this->request->withPost(array_merge($this->request->post(), ['packvip_time' => $timestamp]));
                }
            } else {
                // 否则使用会员天数增加模式（向后兼容）
                $days = (int) $this->request->post('packvip_days', 0);
                if ($days > 0) {
                    $this->request->withPost(array_merge($this->request->post(), ['packvip_time' => time() + $days * 86400]));
                }
            }

            parent::edit();
        }

        unset($row->salt);
        $row->password = '';
        // 会员剩余天数 + 到期展示（供表单回填）
        $pt = (int) $row->packvip_time;
        $row->packvip_days      = '';
        $row->packvip_time_text = $pt ? date('Y-m-d H:i:s', $pt) : '未开通';
        // 供日期时间选择器回填（YYYY-MM-DD HH:mm:ss 格式）
        $row->packvip_time_input = $pt ? date('Y-m-d H:i:s', $pt) : '';
        $this->success('', [
            'row' => $row
        ]);
    }

    /**
     * 重写select
     * remoteSelect 下拉/回填专用：不 withJoin(userGroup) —— 否则 initValue 落在未加别名的
     * 主键 id 上会与 join 表的 id 冲突（Column 'id' is ambiguous）。展示「用户名(对接PID)」。
     * @throws Throwable
     */
    public function select(): void
    {
        list($where, $alias, $limit, $order) = $this->queryBuilder();
        $res = $this->model
            ->withoutField('password,salt')
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        foreach ($res as $re) {
            $re->nickname_text = $re->username . '(' . $re->pid . ')';
        }

        $this->success('', [
            'list'   => $res->items(),
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }
}