<?php

declare(strict_types=1);

namespace Datamints\Feuser\Domain\Repository;

use Doctrine\DBAL\Exception;
use PDO;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class FeUserRepository
{
	public function __construct(private int $storagePageId)
	{
	}

	/**
	 * @throws Exception
	 */
	public function findOneByEmail(string $emailValue): array
	{
		$queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('fe_users');

		//
		//$res = $this->databaseConnection->exec_SELECTquery(
		//'uid, tx_datamintsfeuser_approval_level',
		// 'fe_users',
		// 'pid = ' . $this->storagePageId . ' AND email = ' . $this->databaseConnection->fullQuoteStr(strtolower((string)$this->piVars[$this->contentId][self::specialfieldKeyResendactivation]), 'fe_users') . ' AND disable = 1 AND deleted = 0', '', '', '1');
		//$emailValue = strtolower((string)$this->piVars[$this->contentId][self::specialfieldKeyResendactivation]);

		$conditions = $queryBuilder->expr()->and(
			$queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($this->storagePageId, \PDO::PARAM_INT)),
			$queryBuilder->expr()->eq('email', $queryBuilder->createNamedParameter($emailValue, \PDO::PARAM_STR)),
			$queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)),
			$queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
		);

		return $queryBuilder
			->select('*')
			->from('fe_users')
			->where($conditions)
			->setMaxResults(1)
			->executeQuery()
			->fetchAllAssociative();
	}

	/**
	 * @throws Exception
	 */
	public function countByUidOrEmail(string $value): int
	{
		$queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('fe_users');

		$uidValue = (int)$value;
		$emailValue = strtolower($value);

		$expr = $queryBuilder->expr();
		// TODO was ist mit den restrictions
		$queryBuilder
			->selectLiteral('COUNT(uid) as count')
			->from('fe_users')
			->where(
				$expr->and(
					$expr->eq('pid', $queryBuilder->createNamedParameter($this->storagePageId, PDO::PARAM_INT)),
					$expr->eq('disable', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)),
					$expr->eq('deleted', $queryBuilder->createNamedParameter(0, PDO::PARAM_INT)),
					$expr->or(
						$expr->eq('uid', $queryBuilder->createNamedParameter($uidValue, PDO::PARAM_INT)),
						$expr->eq('email', $queryBuilder->createNamedParameter($emailValue, PDO::PARAM_STR))
					)
				)
			);

		$row = $queryBuilder->executeQuery()->fetchAssociative();

		return (int)$row['count'];
	}

	/**
	 * @param bool $checkUid Beim Bearbeiten, den eigenen Datensatz nicht ueberpruefen.
	 * @param bool $checkPid Wenn beim Bearbeiten keine "userfolder" gesetzt ist, soll global ueberprueft werden, ansonsten nur im Storage!
	 * @throws Exception
	 */
	public function countByFieldAndValue(string $fieldName, string $fieldValue, int $userId, bool $checkUid, bool $checkPid): int
	{
		$queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('fe_users');

		$storagePageId = $this->storagePageId;
		$expr = $queryBuilder->expr();

		$and = [
			$expr->eq($fieldName, $queryBuilder->createNamedParameter($fieldValue, \PDO::PARAM_STR)),
			$expr->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
		];
		if ($checkUid) {
			$expr->neq('uid', $queryBuilder->createNamedParameter($userId, \PDO::PARAM_INT));
		}
		if ($checkPid) {
			$expr->eq('pid', $queryBuilder->createNamedParameter($storagePageId, \PDO::PARAM_INT));
		}

		$conditions = $expr->and(...$and);

		$row = $queryBuilder
			->selectLiteral('COUNT(uid) as count')
			->from('fe_users')
			->where($conditions)
			->executeQuery()
			->fetchAssociative();

		return (int)$row['count'];
	}

	public function update(int $userId, array $arrUpdate): int
	{
		$queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('fe_users');

		$queryBuilder->update('fe_users');
		foreach ($arrUpdate as $field => $value) {

			$queryBuilder->set($field, $value);
		}
		$queryBuilder->where('uid = :uid');
		$queryBuilder->setParameter('uid', $userId, PDO::PARAM_INT);
		return $queryBuilder->executeStatement();
	}

	public function delete(int $userId): int
	{
		$queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('fe_users');

		$queryBuilder->delete('fe_users')->where('uid = :uid');
		$queryBuilder->setParameter('uid', $userId, PDO::PARAM_INT);
		return $queryBuilder->executeStatement();
	}

	public function insert(array $arrUpdate): int
	{
		$queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('fe_users');

		return $queryBuilder->insert('fe_users')->values($arrUpdate)->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function findOneByUid(int $userId): array
	{
		$queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('fe_users');

		$queryBuilder->select('*')->from('fe_users')->where('uid = :uid');
		$queryBuilder->setParameter('uid', $userId, PDO::PARAM_INT);
		return $queryBuilder->executeQuery()->fetchAssociative() ?: [];

	}

	public function findByUidsAndDisable(array $arrNotActivated, int $disable): array
	{
		//		$res = $this->databaseConnection->exec_SELECTquery('uid', 'fe_users', 'uid IN(' . implode(',', $arrNotActivated) . ') AND disable = 1 AND deleted = 0');
		$queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('fe_users');
		array_walk($arrNotActivated, function (&$value) {
			$value = (int)$value;
		});
		$queryBuilder->select('*')->from('fe_users')->where(
			$queryBuilder->expr()->in('uid', $arrNotActivated),
			$queryBuilder->expr()->eq('disable', $disable)
		);
		return $queryBuilder->executeQuery()->fetchAllAssociative() ?: [];
	}
}
