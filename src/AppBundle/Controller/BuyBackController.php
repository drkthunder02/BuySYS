<?php
namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\Time;

use AppBundle\Form\BuyBackType;
use AppBundle\Model\BuyBackModel;
use AppBundle\Model\BuyBackItemModel;
use AppBundle\Entity\LineItemEntity;
use EveBundle\Entity\TypeEntity;
use AppBundle\Entity\CacheEntity;
use AppBundle\Entity\TransactionEntity;
use AppBundle\Helper\MarketHelper;

class BuyBackController extends Controller
{
    /**
     * @Route("/buyback/estimate", name="ajax_estimate_buyback")
     */
    public function ajax_EstimateAction(Request $request) {

        $buyback = new BuyBackModel();
        $form = $this->createForm(new BuyBackType(), $buyback);
        $form->handleRequest($request);

        $types = $this->getDoctrine()->getRepository('EveBundle:TypeEntity', 'evedata');
        $cache = $this->getDoctrine()->getRepository('AppBundle:CacheEntity', 'default');
        $items = array();
        $typeids = array();

        // Build our Item List and TypeID List
        foreach(explode("\n", $buyback->getItems()) as $line) {

            // Array counts
            // 5 -> View Contents list
            // 6 -> Inventory list

            // Split by TAB
            $item = explode("\t", $line);

            // Did this contain tabs?
            if(count($item) > 1) {

                // 6 Columns -> Means this is pasted from Inventory Screen
                if(count($item) == 6) {

                    // Get TYPE from Eve Database
                    $type = $types->findOneByTypeName($item[0]);

                    // Create & Populate our BuyBackItemModel
                    $lineItem = new BuyBackItemModel();
                    $lineItem->setTypeId($type->getTypeId());

                    if($item[1] == "") {
                        $lineItem->setQuantity(1);
                    } else {
                        $lineItem->setQuantity(str_replace(',', '', $item[1]));
                    }

                    $lineItem->setName($type->getTypeName());
                    $lineItem->setVolume($type->getVolume());

                    $items[] = $lineItem;

                    // Build our list of TypeID's
                    $typeids[] = $type->getTypeId();
                }
            } else {

                // Didn't contain tabs, so user typed it in?  Try to preg match it
                $item = array();
                preg_match("/((\d|,)*)\s+(.*)/", $line, $item);

                // Get TYPE from Eve Database
                $type = $types->findOneByTypeName($item[3]);

                if($type != null) {

                    // Create & Populate our BuyBackItemModel
                    $lineItem = new BuyBackItemModel();
                    $lineItem->setTypeId($type->getTypeId());
                    $lineItem->setQuantity(str_replace(',', '', $item[1]));
                    $lineItem->setName($type->getTypeName());
                    $lineItem->setVolume($type->getVolume());

                    $items[] = $lineItem;

                    // Build our list of TypeID's
                    $typeids[] = $type->getTypeId();
                }
            }
        }

        $priceLookup = MarketHelper::GetMarketPrices($typeids, $this);

        $totalValue = 0;
        $ajaxData = "[";

        foreach($items as $lineItem) {

            $value = ((int)$lineItem->getQuantity() * $priceLookup[$lineItem->getTypeId()]) * .85;
            $totalValue += $value;
            $lineItem->setValue($value);
            $ajaxData .= "{ typeid:" . $lineItem->getTypeId() . ", quantity:" . $lineItem->getQuantity() . "},";
        }

        $ajaxData .= "]";
        $ajaxData = rtrim($ajaxData, ",");

        if($items != null) {

            $template = $this->render('buyback/results.html.twig', Array ( 'items' => $items, 'total' => $totalValue, 'ajaxData' => $ajaxData ));
        } else {

            $template = $this->render('buyback/novalid.html.twig');
        }

        return $template;
    }

    /**
     * @Route("/buyback/accept", name="ajax_accept_buyback")
     */
    public function ajax_AcceptAction(Request $request) {

        // Get our list of Items
        $items = $request->request->get('items');

        // Generate list of unique items to pull from cache
        $typeids = Array();
        $typeids = array_unique(array_map(function($n){return($n['typeid']);}, $items));

        // Get Type Database
        $types = $this->getDoctrine()->getRepository('EveBundle:TypeEntity', 'evedata');

        // Pull data from Cache
        $em = $this->getDoctrine()->getManager('default');
        $query = $em->createQuery('SELECT c FROM AppBundle:CacheEntity c WHERE c.typeID IN (:types)')->setParameter('types', $typeids);
        $cached = $query->getResult();

        $transaction = new TransactionEntity();
        $transaction->setUser($this->getUser());
        $transaction->setType("P");
        $transaction->setIsComplete(false);
        $transaction->setOrderId($transaction->getType() . uniqid());
        $transaction->setGross(0);
        $transaction->setNet(0);
        $transaction->setCreated(new \DateTime("now"));
        $em->persist($transaction);

        $gross = 0;
        $net = 0;

        foreach($items as $item) {

            $lineItem = new LineItemEntity();
            $lineItem->setTypeId($item['typeid']);
            $lineItem->setQuantity($item['quantity']);
            $lineItem->setName( $types->findOneByTypeID($item['typeid'])->getTypeName() );
            $lineItem->setTax(15);

            foreach($cached as $cache) {

                if($cache->getTypeId() == $lineItem->getTypeId()) {

                    $lineItem->setMarketPrice($cache->getMarket());
                    $lineItem->setGrossPrice(($lineItem->getMarketPrice() * $lineItem->getQuantity()));
                    $gross +=  $lineItem->getGrossPrice();
                    $lineItem->setNetPrice(($lineItem->getMarketPrice() * $lineItem->getQuantity()) * ((100-$lineItem->getTax())/100));
                    $net += $lineItem->getNetPrice();
                    break;
                }
            }

            $transaction->addLineItem($lineItem);
            $em->persist($lineItem);
        }

        $transaction->setGross($gross);
        $transaction->setNet($net);

        //$em->persist($transaction);
        $em->flush();

        $template = $this->render('buyback/accepted.html.twig', Array ( 'auth_code' => $transaction->getOrderId(), 'total_value' => $net, 'transaction' => $transaction ));
        return $template;
    }
}
