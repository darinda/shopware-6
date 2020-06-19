<?php declare(strict_types=1);

namespace WalleePayment\Core\Api\Refund\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\{
	Framework\Context,
	Framework\DataAbstractionLayer\Search\Criteria,
	Framework\Uuid\Uuid};
use Wallee\Sdk\{
	Model\Refund,
	Model\Transaction};
use WalleePayment\Core\{
	Api\Refund\Entity\RefundEntity,
	Api\Transaction\Entity\TransactionEntity,
	Settings\Service\SettingsService,
	Util\Payload\RefundPayload};

/**
 * Class RefundService
 *
 * @package WalleePayment\Core\Api\Refund\Service
 */
class RefundService {

	/**
	 * @var \Psr\Container\ContainerInterface
	 */
	protected $container;

	/**
	 * @var \Psr\Log\LoggerInterface
	 */
	private $logger;

	/**
	 * @var \WalleePayment\Core\Settings\Service\SettingsService
	 */
	private $settingsService;

	/**
	 * RefundService constructor.
	 *
	 * @param \Psr\Container\ContainerInterface                                   $container
	 * @param \WalleePayment\Core\Settings\Service\SettingsService $settingsService
	 * @param \Psr\Log\LoggerInterface                                            $logger
	 */
	public function __construct(
		ContainerInterface $container,
		SettingsService $settingsService,
		LoggerInterface $logger
	)
	{
		$this->container       = $container;
		$this->settingsService = $settingsService;
		$this->logger          = $logger;
	}


	/**
	 * The pay function will be called after the customer completed the order.
	 * Allows to process the order and store additional information.
	 *
	 * A redirect to the url will be performed
	 *
	 * @param \Wallee\Sdk\Model\Transaction $transaction
	 * @param float                                        $refundableAmount
	 * @param \Shopware\Core\Framework\Context             $context
	 * @return \Wallee\Sdk\Model\Refund|null
	 * @throws \Exception
	 */
	public function create(Transaction $transaction, float $refundableAmount, Context $context): ?Refund
	{
		try {
			$transactionEntity = $this->getTransactionEntityByTransactionId($transaction->getId(), $context);
			$settings          = $this->settingsService->getSettings($transactionEntity->getSalesChannel()->getId());
			$apiClient         = $settings->getApiClient();
			$refundPayload     = (new RefundPayload($this->logger))->get($transaction, $refundableAmount);
			if (!is_null($refundPayload)) {
				$refund = $apiClient->getRefundService()->refund($settings->getSpaceId(), $refundPayload);
				$this->upsert($refund, $context);
				return $refund;
			}
		} catch (\Exception $exception) {
			$this->logger->critical($exception->getMessage());
		}
		return null;
	}

	/**
	 * Get transaction entity by Wallee transaction id
	 *
	 * @param int                              $transactionId
	 * @param \Shopware\Core\Framework\Context $context
	 * @return \WalleePayment\Core\Api\Transaction\Entity\TransactionEntity
	 */
	public function getTransactionEntityByTransactionId(int $transactionId, Context $context): TransactionEntity
	{
		return $this->container->get('wallee_transaction.repository')
							   ->search(new Criteria(), $context)
							   ->getEntities()
							   ->getByTransactionId($transactionId);
	}

	/**
	 * Persist Wallee transaction
	 *
	 * @param \Shopware\Core\Framework\Context        $context
	 * @param \Wallee\Sdk\Model\Refund $refund
	 */
	public function upsert(Refund $refund, Context $context): void
	{
		$refundEntity = $this->getByRefundId($refund->getId(), $context);
		$id           = is_null($refundEntity) ? Uuid::randomHex() : $refundEntity->getId();
		try {

			$data = [
				'id'            => $id,
				'data'          => json_decode(strval($refund), true),
				'refundId'      => $refund->getId(),
				'spaceId'       => $refund->getLinkedSpaceId(),
				'state'         => $refund->getState(),
				'transactionId' => $refund->getTransaction()->getId(),
			];

			$data = array_filter($data);
			$this->container->get('wallee_refund.repository')->upsert([$data], $context);

		} catch (\Exception $exception) {
			$this->logger->critical(__CLASS__ . ' : ' . __FUNCTION__ . ' : ' . $exception->getMessage());
		}
	}

	/**
	 * Get transaction entity by Wallee transaction id
	 *
	 * @param int                              $refundId
	 * @param \Shopware\Core\Framework\Context $context
	 * @return \WalleePayment\Core\Api\Refund\Entity\RefundEntity|null
	 */
	public function getByRefundId(int $refundId, Context $context): ?RefundEntity
	{
		return $this->container->get('wallee_refund.repository')
							   ->search(new Criteria(), $context)
							   ->getEntities()
							   ->getByRefundId($refundId);
	}

}