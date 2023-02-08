<?php

declare(strict_types=1);

namespace longlang\phpkafka\Producer;

use longlang\phpkafka\Broker;
use longlang\phpkafka\Producer\Partitioner\PartitionerInterface;
use longlang\phpkafka\Protocol\ErrorCode;
use longlang\phpkafka\Protocol\Produce\PartitionProduceData;
use longlang\phpkafka\Protocol\Produce\ProduceRequest;
use longlang\phpkafka\Protocol\Produce\ProduceResponse;
use longlang\phpkafka\Protocol\Produce\TopicProduceData;
use longlang\phpkafka\Protocol\RecordBatch\Record;
use longlang\phpkafka\Protocol\RecordBatch\RecordBatch;
use longlang\phpkafka\Protocol\RecordBatch\RecordHeader;

class Producer
{
    /**
     * @var ProducerConfig
     */
    protected $config;

    /**
     * @var Broker
     */
    protected $broker;

    /**
     * @var PartitionerInterface
     */
    protected $partitioner;

    public function __construct(ProducerConfig $config)
    {
        $this->config = $config;
        $this->broker = $broker = new Broker($config);
        if ($config->getUpdateBrokers()) {
            $broker->updateBrokers();
        } else {
            $broker->setBrokers($config->getBrokers());
        }
        $class = $config->getPartitioner();
        $this->partitioner = new $class();
    }

    /**
     * @param RecordHeader[]|array $headers
     */
    public function send(string $topic, ?string $value, ?string $key = null, array $headers = [], ?int $partitionIndex = null)
    {
        $message = new ProduceMessage($topic, $value, $key, $headers, $partitionIndex);
        $messages = [$message];
        return $this->sendBatch($messages);
    }

    /**
     * @param ProduceMessage[] $messages
     */
    public function sendBatch(array $messages)
    {
        $times = [];
        $config = $this->config;
        $broker = $this->broker;
        $request = new ProduceRequest();
        $request->setAcks($acks = $config->getAcks());
        $recvTimeout = $config->getRecvTimeout();
        if ($recvTimeout < 0) {
            $request->setTimeoutMs(60000);
        } else {
            $request->setTimeoutMs((int) ($recvTimeout * 1000));
        }

        $timestamp = (int) (microtime(true) * 1000);
        /** @var TopicProduceData[][] $topicsMap */
        $topicsMap = [];
        $partitionsMap = [];
        $topics = [];
        $getTopicTime = microtime(true);
        foreach ($messages as $message) {
            $topics[] = $message->getTopic();
        }
        $times[] = ['getTopic' => microtime(true) - $getTopicTime];
        $getTopicMeta = microtime(true);
        $topicsMeta = $broker->getTopicsMeta($topics);
        $times[] = ['getTopicMeta' => microtime(true) - $getTopicMeta];
        foreach ($messages as $message) {
            $topicName = $message->getTopic();
            $value = $message->getValue();
            $key = $message->getKey();
            $getPartition = microtime(true);
            $partitionIndex = $message->getPartitionIndex() ?? $this->partitioner->partition($topicName, $value, $key, $topicsMeta);
            $times[] = ['getPartition' => microtime(true) - $getPartition];
            $getBrokerId = microtime(true);
            $brokerId = $broker->getBrokerIdByTopic($topicName, $partitionIndex);
            $times[] = ['getBrokerId' => microtime(true) - $getBrokerId];
            $getPartitions = microtime(true);
            if (isset($topicsMap[$brokerId][$topicName])) {
                /** @var TopicProduceData $topicData */
                $topicData = $topicsMap[$brokerId][$topicName];
                $partitions = $topicData->getPartitions();
            } else {
                $topicData = $topicsMap[$brokerId][$topicName] = new TopicProduceData();
                $topicData->setName($topicName);
                $partitions = [];
            }
            $times[] = ['getPartitions' => microtime(true) - $getPartitions];
            $getRecords = microtime(true);
            if (isset($partitionsMap[$topicName][$partitionIndex])) {
                /** @var PartitionProduceData $partition */
                $partition = $partitionsMap[$topicName][$partitionIndex];
                $recordBatch = $partition->getRecords();
                $records = $recordBatch->getRecords();
            } else {
                $partition = $partitions[] = $partitionsMap[$topicName][$partitionIndex] = new PartitionProduceData();
                $partition->setPartitionIndex($partitionIndex);
                $partition->setRecords($recordBatch = new RecordBatch());
                $recordBatch->setProducerId($config->getProducerId());
                $recordBatch->setProducerEpoch($config->getProducerEpoch());
                $recordBatch->setPartitionLeaderEpoch($config->getPartitionLeaderEpoch());
                $recordBatch->setFirstTimestamp($timestamp);
                $recordBatch->setMaxTimestamp($timestamp);
                $recordBatch->setLastOffsetDelta(-1);
                $records = [];
            }
            $times[] = ['getRecords' => microtime(true) - $getRecords];
            $offsetDelta = $recordBatch->getLastOffsetDelta() + 1;
            $recordBatch->setLastOffsetDelta($offsetDelta);
            $record = $records[] = new Record();
            $record->setKey($key);
            $record->setValue($value);
            $headers = [];
            foreach ($message->getHeaders() as $key => $value) {
                if ($value instanceof RecordHeader) {
                    $headers[] = $value;
                // @phpstan-ignore-next-line
                } else {
                    $headers[] = (new RecordHeader())->setHeaderKey($key)->setValue($value);
                }
            }
            $record->setHeaders($headers);
            $record->setOffsetDelta($offsetDelta);
            $record->setTimestampDelta(((int) (microtime(true) * 1000)) - $timestamp);
            $recordBatch->setRecords($records);

            $topicData->setPartitions($partitions);
        }
        $retries = microtime(true);
        $produceRetry = $config->getProduceRetry();
        $produceRetrySleep = $config->getProduceRetrySleep();
        foreach ($topicsMap as $brokerId => $topics) {
            $retryTopics = [];
            for ($retryCount = 0; $retryCount <= $produceRetry; ++$retryCount) {
                if ($retryTopics) {
                    foreach ($topics as $k => $v) {
                        $name = $v->getName();
                        if (isset($retryTopics[$name])) {
                            $partitions = $v->getPartitions();
                            foreach ($partitions as $i => $partition) {
                                if (!\in_array($partition->getPartitionIndex(), $retryTopics[$name])) {
                                    unset($partitions[$i]);
                                }
                            }
                            $v->setPartitions($partitions);
                        } else {
                            unset($topics[$k]);
                        }
                    }
                }
                $request->setTopics($topics);

                $hasResponse = 0 !== $acks;
                $client = $broker->getClient($brokerId);
                $correlationId = $client->send($request, null, $hasResponse);
                if (!$hasResponse) {
                    break;
                }
                /** @var ProduceResponse $response */
                $response = $client->recv($correlationId);
                $retryTopics = [];
                foreach ($response->getResponses() as $response) {
                    $topicName = $response->getName();
                    foreach ($response->getPartitions() as $partition) {
                        $errorCode = $partition->getErrorCode();
                        switch ($errorCode) {
                            case ErrorCode::UNKNOWN_TOPIC_OR_PARTITION:
                            case ErrorCode::LEADER_NOT_AVAILABLE:
                                $retryTopics[$topicName][] = $partition->getPartitionIndex();
                                break;
                            default:
                                ErrorCode::check($errorCode);
                        }
                    }
                }
                if (!$retryTopics) {
                    break;
                }
                usleep((int) ($produceRetrySleep * 1000000));
            }
        }
        $times[] = ['retries' => microtime(true) - $retries];
        return $times;
    }

    public function close(): void
    {
        $this->broker->close();
    }

    public function getConfig(): ProducerConfig
    {
        return $this->config;
    }

    public function getBroker(): Broker
    {
        return $this->broker;
    }
}
