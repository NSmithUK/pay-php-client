<?php
namespace Alphagov\Pay\Response;

use GuzzleHttp\Psr7\Uri;

class Payment extends AbstractResponse {

    public function canContinue(){

        if( !isset( $this['_links']['next_url']['href'] ) ){
            return false;
        }


        return true;

    }

    public function getPaymentPageUrl(){

        if( !$this->canContinue() ){
            return false;
        }

        return new Uri( $this['_links']['next_url']['href'] );

    }

}
