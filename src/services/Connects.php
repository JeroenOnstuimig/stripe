<?php
/**
 * Stripe Payments plugin for Craft CMS 3.x
 *
 * @link      https://enupal.com/
 * @copyright Copyright (c) 2018 Enupal LLC
 */

namespace enupal\stripe\services;

use craft\base\ElementInterface;
use craft\db\Query;
use craft\helpers\Db;
use craft\services\Plugins;
use enupal\stripe\elements\Commission;
use enupal\stripe\elements\Connect;
use enupal\stripe\elements\PaymentForm;
use enupal\stripe\Stripe;
use enupal\stripe\Stripe as StripePlugin;
use Stripe\Exception\OAuth\InvalidGrantException;
use Stripe\OAuth;
use yii\base\Component;
use Craft;

class Connects extends Component
{
    const COMMERCE_NAMESPACE = 'craft\commerce\elements\Product';
    /**
     * Returns a Connect model if one is found in the database by id
     *
     * @param int $id
     *
     * @return null|Connect
     */
    public function getConnectById(int $id)
    {
        $connect = Craft::$app->getElements()->getElementById($id);

        return $connect;
    }

    /**
     * @return array
     */
    public function getConnectProductTypes()
    {
        $productTypes = [
            PaymentForm::class
        ];

        if ($this->isCommerceInstalled()) {
            $productTypes[] = self::COMMERCE_NAMESPACE;
        }

        return $productTypes;
    }

    /**
     * @return array
     */
    public function getConnectProductTypesAsOptions()
    {
        $productTypes = $this->getConnectProductTypes();
        $options = [];

        foreach ($productTypes as $productType) {
            $name = $productType::displayName();
            $name = $productType === self::COMMERCE_NAMESPACE ? $name. ' (Commerce)' : $name;
            $options[] = [
                'label' => $name,
                'value' => $productType
            ];
        }

        return $options;
    }

    /**
     * @param string $productType
     *
     * @return Connect
     * @throws \Exception
     * @throws \Throwable
     */
    public function createNewConnect(string $productType): Connect
    {
        $settings = StripePlugin::$app->settings->getSettings();
        $connect = new Connect();

        $connect->productType = $productType;
        $connect->enabled = 0;
        $connect->rate = $settings->globalRate;

        Craft::$app->elements->saveElement($connect, false);

        return $connect;
    }

    /**
     * @return bool
     */
    public function isCommerceInstalled()
    {
        $pluginHandle = 'commerce';
        $projectConfig = Craft::$app->getProjectConfig();
        $commerceSettings = $projectConfig->get(\craft\services\ProjectConfig::PATH_PLUGINS.'.'.$pluginHandle);
        $isInstalled = $commerceSettings['enabled'] ?? false;

        return $isInstalled;
    }

    /**
     * @param Connect $connect
     *
     * @return Connect
     */
    public function populateConnectFromPost(Connect $connect)
    {
        $request = Craft::$app->getRequest();

        $postFields = $request->getBodyParam('fields');

        $postFields['allProducts'] = filter_var($postFields['allProducts'], FILTER_VALIDATE_BOOLEAN);

        $postFields['vendorId'] = is_array($postFields['vendorId']) ? $postFields['vendorId'][0] : $postFields['vendorId'];

        $connect->setAttributes(/** @scrutinizer ignore-type */
            $postFields, false);

        return $connect;
    }

    /**
     * @param Connect $connect
     *
     * @return bool
     * @throws \Throwable
     */
    public function deleteConnect(Connect $connect)
    {
        $transaction = Craft::$app->db->beginTransaction();

        try {
            // Delete the commissions
            $commissions = (new Query())
                ->select(['id'])
                ->from(["{{%enupalstripe_commissions}}"])
                ->where(['connectId' => $connect->id])
                ->all();

            foreach ($commissions as $commission) {
                Craft::$app->elements->deleteElementById($commission['id'], Commission::class, null, true);
            }

            // Delete the Connect
            $success = Craft::$app->elements->deleteElementById($connect->id, Connect::class,null, true);

            if (!$success) {
                $transaction->rollback();
                Craft::error("Couldn’t delete Connect", __METHOD__);

                return false;
            }

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollback();

            throw $e;
        }

        return true;
    }

    /**
     * @param $code
     * @return mixed|null
     * @throws \Exception
     */
    public function getStripeUserIdFromCode($code)
    {
        StripePlugin::$app->settings->initializeStripe();

        try {
            $stripeResponse =  OAuth::token([
                'grant_type' => 'authorization_code',
                'code' => $code,
            ]);
        } catch (InvalidGrantException $e) {
            Craft::error('Invalid authorization code: ' . $code, __METHOD__);
            return null;
        } catch (\Exception $e) {
            Craft::error('An unknown error occurred. '.$e->getMessage(), __METHOD__);
            return null;
        }

        return $stripeResponse->stripe_user_id;
    }

    /**
     * @param $paymentFormId
     * @param $vendorId
     * @return array|ElementInterface[]|null
     */
    public function getConnectByPaymentFormId($paymentFormId, $vendorId = null)
    {
        $query = Connect::find();

        $query->andWhere(['like', 'products', '%"'.$paymentFormId . '"%', false]);
        $query->andWhere(Db::parseParam(
            'enupalstripe_connect.productType', PaymentForm::class));

        if ($vendorId !== null) {
            $query->andWhere(Db::parseParam(
                'enupalstripe_connect.vendorId', $vendorId));
        }

        return $query->one();
    }

    /**
     * @param $paymentFormId
     * @param $vendorId
     * @param $productType
     * @return array|ElementInterface[]|null
     */
    public function getConnectsByPaymentFormId($paymentFormId, $vendorId = null, $productType = PaymentForm::class)
    {
        $query = Connect::find();

        $query->andWhere(['like', 'products', '%"'.$paymentFormId . '"%', false]);

        $query->andWhere(Db::parseParam(
            'enupalstripe_connect.productType', $productType));
        $query->andWhere(Db::parseParam(
            'enupalstripe_connect.allProducts', false));

        if ($vendorId !== null) {
            $query->andWhere(Db::parseParam(
                'enupalstripe_connect.vendorId', $vendorId));
        }

        return $query->all();
    }

    /**
     * @param $productType
     * @return array|ElementInterface[]|null
     */
    public function getConnectsWithAllProducts($productType = PaymentForm::class)
    {
        $query = Connect::find();

        $query->andWhere(Db::parseParam(
            'enupalstripe_connect.productType', $productType));
        $query->andWhere(Db::parseParam(
            'enupalstripe_connect.allProducts', true));

        return $query->all();
    }

    /**
     * @param int $vendorId
     * @param $allProducts
     * @param $productType
     * @return array|Connect[]|null
     */
    public function getConnectsByVendorId(int $vendorId, $allProducts = null, $productType = PaymentForm::class)
    {
        $query = Connect::find();

        $query->andWhere(Db::parseParam('enupalstripe_connect.vendorId', $vendorId));
        if ($allProducts) {
            $query->andWhere(Db::parseParam(
                'enupalstripe_connect.allProducts', $allProducts));
        }
        if ($productType) {
            $query->andWhere(Db::parseParam(
                'enupalstripe_connect.productType', $productType));
        }

        return $query->all();
    }
}
