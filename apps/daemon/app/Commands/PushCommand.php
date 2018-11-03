<?php

namespace Apps\Daemon\Commands;

use Mix\Redis\Persistent\RedisConnection;
use Mix\Console\ExitCode;
use Mix\Facades\Input;
use Mix\Task\CenterWorker;
use Mix\Task\LeftWorker;
use Mix\Task\ProcessPoolTaskExecutor;

/**
 * 推送模式范例
 * @author 刘健 <coder.liu@qq.com>
 */
class PushCommand extends BaseCommand
{

    // 初始化事件
    public function onInitialize()
    {
        parent::onInitialize(); // TODO: Change the autogenerated stub
        // 获取程序名称
        $this->programName = Input::getCommandName();
        // 设置pidfile
        $this->pidFile = "/var/run/{$this->programName}.pid";
    }

    /**
     * 获取服务
     * @return ProcessPoolTaskExecutor
     */
    public function getTaskService()
    {
        return new ProcessPoolTaskExecutor([
            // 服务名称
            'name'          => "mix-daemon: {$this->programName}",
            // 执行模式
            'mode'          => ProcessPoolTaskExecutor::MODE_PUSH | ProcessPoolTaskExecutor::MODE_DAEMON,
            // 左进程数
            'leftProcess'   => 1,
            // 中进程数
            'centerProcess' => 5,
            // 最大执行次数
            'maxExecutions' => 16000,
            // 队列名称
            'queueName'     => __FILE__,
            // 临时文件目录，当消息长度超过8K时会启用临时文件来保存，建议使用tmpfs文件系统提升性能
            'tempDir'       => '/dev/shm',
        ]);
    }

    // 启动
    public function actionStart()
    {
        // 预处理
        if (!parent::actionStart()) {
            return ExitCode::UNSPECIFIED_ERROR;
        }
        // 启动服务
        $service = $this->getTaskService();
        $service->on('LeftStart', [$this, 'onLeftStart']);
        $service->on('CenterStart', [$this, 'onCenterStart']);
        $service->on('CenterMessage', [$this, 'onCenterMessage']);
        $service->start();
        // 返回退出码
        return ExitCode::OK;
    }

    // 平滑重启
    public function actionRestart()
    {
        $this->actionStop(true);
        $this->actionStart();
        // 返回退出码
        return ExitCode::OK;
    }

    // 左进程启动事件回调函数
    public function onLeftStart(LeftWorker $worker)
    {
        // 使用长连接客户端，这样会自动帮你维护连接不断线
        $redis = RedisConnection::newInstanceByConfig('libraries.[persistent.redis]');
        // 通过循环保持任务执行状态
        while (true) {
            // 从消息队列中间件阻塞获取一条消息
            $data = $redis->brpop('KEY', 30);
            if (!empty($data)) {
                $data = unserialize(array_pop($data));
            }
            /**
             * 将消息发送给中进程去处理
             * 发送方法内有信号判断处理，当接收到重启、停止信号会立即退出左进程
             * 当发送的数据为空时，并不会触发 onCenterMessage，但可以触发信号判断处理，所以当 pop 为空时，请照常 send 给中进程。
             */
            $worker->send($data);
        }
    }

    // 中进程启动事件
    public function onCenterStart(CenterWorker $worker)
    {
        // 可以在这里实例化一些对象，供 onCenterMessage 中使用，这样就不需要重复实例化。
    }

    // 中进程消息事件回调函数
    public function onCenterMessage(CenterWorker $worker, $data)
    {
        // 处理消息，比如：发送短信、发送邮件、微信推送
        // ...
    }

}
