<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packagist\WebBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Igor Wiedler <igor@wiedler.ch>
 */
class StatsServerCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('packagist:stats-server')
            ->setDescription('Serves a real-time stream of statistics over event-source')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $port = 8080;

        $output->writeln("Stats server is listening on port $port");

        $loop = \React\EventLoop\Factory::create();
        $socket = new \React\Socket\Server($loop);
        $http = new \React\Http\Server($socket);

        $emitter = new \Evenement\EventEmitter();

        $redis = new \Predis\Async\Client('tcp://127.0.0.1:6379', $loop);
        $redis->connect(function () use ($redis, $emitter) {
            $channels = array('pub:downloads', 'pub:foo');
            $redis->subscribe($channels, function ($event) use ($emitter) {
                list(, $chan, $msg) = $event;
                $emitter->emit('message', array($chan, $msg));
            });
        });

        $http->on('request', function ($request, $response) use ($emitter) {
            $headers = array(
                'Content-Type'  => 'text/event-stream',
                'Access-Control-Allow-Origin' => '*',
            );
            $response->writeHead(200, $headers);

            $emitter->on('message', function ($chan, $msg) use ($response) {
                $data = \Igorw\EventSource\Event::create()
                    ->setEvent($chan)
                    ->setData($msg)
                    ->dump();

                $response->write($data);
            });
        });

        $socket->listen($port);
        $loop->run();
    }
}
