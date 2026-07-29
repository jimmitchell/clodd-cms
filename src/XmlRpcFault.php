<?php

declare(strict_types=1);

namespace CMS;

/**
 * An XML-RPC fault raised by a handler.
 *
 * The handlers used to call a function that echoed a fault envelope and exited.
 * Throwing instead keeps the response format in one place — the front
 * controller — and makes the handlers testable, since a test can catch this
 * where it could not observe an exit().
 *
 * The exception code carries the XML-RPC faultCode.
 */
class XmlRpcFault extends \RuntimeException
{
}
