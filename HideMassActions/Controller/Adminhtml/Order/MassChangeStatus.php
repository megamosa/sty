<?php
namespace MagoArab\HideMassActions\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Sales\Model\OrderRepository;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class MassChangeStatus extends Action
{
    /**
     * @var Filter
     */
    protected $filter;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var ResourceConnection
     */
    protected $resourceConnection;

    /**
     * @var OrderRepository
     */
    protected $orderRepository;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param GridResource $gridResource
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        ResourceConnection $resourceConnection = null,
        OrderRepository $orderRepository = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->resourceConnection = $resourceConnection ?: $context->getObjectManager()->get(ResourceConnection::class);
        $this->orderRepository = $orderRepository ?: $context->getObjectManager()->get(OrderRepository::class);
        $this->logger = $logger ?: $context->getObjectManager()->get(LoggerInterface::class);
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        try {
            // جمع الطلبات المختارة من واجهة المستخدم
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $collectionSize = $collection->getSize();
            
            // الحصول على حالة الطلب المطلوبة من المعلمات
            $status = $this->getRequest()->getParam('status');
            
            if (!$status) {
                throw new LocalizedException(__('No status specified.'));
            }

            $orderUpdated = 0;
            $orderErrors = 0;
            $orderIds = [];

            // استخدام المجموعات لتقليل الحمل على الخادم
            $batchSize = 20;
            $currentBatch = 0;
            $batchedCollection = [];

            // تقسيم المجموعة إلى مجموعات أصغر
            foreach ($collection as $order) {
                $batchedCollection[$currentBatch][] = $order;
                $orderIds[] = $order->getId();
                
                if (count($batchedCollection[$currentBatch]) >= $batchSize) {
                    $currentBatch++;
                }
            }

            // معالجة كل مجموعة على حدة
            foreach ($batchedCollection as $batch) {
                foreach ($batch as $order) {
                    try {
                        // تحديد state مناسب بناءً على الحالة
                        $state = $this->getOrderState($status);
                        
                        // تغيير state إذا كان موجوداً
                        if ($state) {
                            $order->setState($state);
                        }
                        
                        // تغيير الحالة
                        $order->setStatus($status);
                        $order->addCommentToStatusHistory(
                            __('Status updated via Mass Action'),
                            false
                        );
                        $order->save();
                        $orderUpdated++;
                    } catch (\Exception $e) {
                        $this->logger->error('Error updating order #' . $order->getIncrementId() . ': ' . $e->getMessage());
                        $orderErrors++;
                    }
                }
            }
            
            // تحديث بيانات الجدول بطريقة ذكية
            $this->updateGridData($orderIds);
            
            if ($orderUpdated) {
                $this->messageManager->addSuccessMessage(
                    __('A total of %1 order(s) have been updated.', $orderUpdated)
                );
            }
            
            if ($orderErrors) {
                $this->messageManager->addErrorMessage(
                    __('A total of %1 order(s) cannot be updated.', $orderErrors)
                );
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->logger->critical($e);
            $this->messageManager->addExceptionMessage(
                $e,
                __('Something went wrong while updating order status.')
            );
        }
        
        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath('sales/order/index');
    }
    
    /**
     * تحديد state الطلب المناسب بناءً على الحالة
     *
     * @param string $status
     * @return string|null
     */
    private function getOrderState($status)
    {
        // خريطة الحالات إلى states
        $statusStateMap = [
            'preparingb' => 'processing',     // طباعة
            'preparinga' => 'processing',     // جاري الشحن
            'deliveredtodayc' => 'processing',  // تم الشحن اليوم
            // يمكنك إضافة المزيد من التخطيطات هنا
        ];
        
        return isset($statusStateMap[$status]) ? $statusStateMap[$status] : null;
    }

    /**
     * تحديث بيانات الجدول بطريقة ذكية لتجنب الحمل الزائد
     *
     * @param array $orderIds
     * @return void
     */
    private function updateGridData(array $orderIds)
    {
        if (empty($orderIds)) {
            return;
        }

        try {
            // الحصول على الاتصال بقاعدة البيانات
            $connection = $this->resourceConnection->getConnection();
            $salesOrderGridTable = $this->resourceConnection->getTableName('sales_order_grid');
            
            // للطلبات الكثيرة، نقوم بالتحديث على دفعات
            $batchSize = 20;
            $totalOrders = count($orderIds);
            $processedBatch = false;
            
            if ($totalOrders > $batchSize) {
                // معالجة الدفعة الأولى فقط
                $currentBatchIds = array_slice($orderIds, 0, $batchSize);
                $this->updateOrdersInGrid($connection, $salesOrderGridTable, $currentBatchIds);
                $processedBatch = true;
                
                // إضافة رسالة للمستخدم
                $this->messageManager->addNoticeMessage(
                    __('The first %1 orders have been updated in the grid. The remaining orders will be updated shortly.', $batchSize)
                );
            } else {
                // تحديث كل الطلبات فورياً
                $this->updateOrdersInGrid($connection, $salesOrderGridTable, $orderIds);
            }
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error('Error updating grid data: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * تحديث الطلبات في جدول Grid
     *
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param string $tableName
     * @param array $orderIds
     * @return void
     */
    private function updateOrdersInGrid($connection, $tableName, array $orderIds)
    {
        if (empty($orderIds) || !$connection) {
            return;
        }
        
        try {
            foreach ($orderIds as $orderId) {
                try {
                    // الحصول على الطلب
                    $order = $this->orderRepository->get($orderId);
                    
                    // تحديث بيانات الطلب في جدول Grid
                    $data = [
                        'status' => $order->getStatus(),
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    
                    // تحديث الصف في جدول Grid
                    $connection->update(
                        $tableName,
                        $data,
                        ['entity_id = ?' => $orderId]
                    );
                } catch (\Exception $e) {
                    if ($this->logger) {
                        $this->logger->warning('Grid update error for order #' . $orderId . ': ' . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error('Batch grid update error: ' . $e->getMessage());
            }
        }
    }
}