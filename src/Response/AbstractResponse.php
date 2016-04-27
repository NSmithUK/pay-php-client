<?php
namespace Alphagov\Pay\Response;

use Alphagov\Pay\Exception\UnexpectedValueException;

abstract class AbstractResponse extends \ArrayObject {

    public function __construct( array $details ){

        parent::__construct( $details, self::ARRAY_AS_PROPS );

    }

    public function __toString(){

        if( !$this->offsetExists('payment_id') ){
            throw new UnexpectedValueException("Payment has not been instantiated with a 'payment_id'");
        }

        return $this->offsetGet('payment_id');

    }

}
