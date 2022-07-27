<?php

namespace I95dev\Newsletter\Model\Sales\Order\Email;

use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\Template\TransportBuilderByStore;
use Magento\Sales\Model\Order\Email\Container\IdentityInterface;
use Magento\Sales\Model\Order\Email\Container\Template;

/**
 * Email sender builder for attachments
 */
class SenderBuilder extends \Magento\Sales\Model\Order\Email\SenderBuilder
{
    /**
     * @param Template $templateContainer
     * @param IdentityInterface $identityContainer
     * @param TransportBuilder $transportBuilder
     * @param TransportBuilderByStore $transportBuilderByStore
     * @param \Magento\Framework\Filesystem\Driver\File $reader
     */
    public function __construct(
        Template $templateContainer,
        IdentityInterface $identityContainer,
        TransportBuilder $transportBuilder,
        TransportBuilderByStore $transportBuilderByStore = null,
        \I95dev\Newsletter\Helper\Data $helper,
        \Magento\Framework\Filesystem\Driver\File $reader
    ) {
        parent::__construct(
            $templateContainer,
            $identityContainer,
            $transportBuilder
        );
        $this->helper = $helper;
        $this->reader = $reader;
    }
    /**
     * Prepare and send email message
     *
     * @return void
     */
    public function send()
    {
        $this->configureEmailTemplate();

        $this->transportBuilder->addTo(
            $this->identityContainer->getCustomerEmail(),
            $this->identityContainer->getCustomerName()
        );

        $copyTo = $this->identityContainer->getEmailCopyTo();
        if (!empty($copyTo) && $this->identityContainer->getCopyMethod() == 'bcc') {
            foreach ($copyTo as $email) {
                $this->transportBuilder->addBcc($email);
            }
        }

        /* to attach events in invoice email */
        $templateVars = $this->templateContainer->getTemplateVars();
        $transport = $this->transportBuilder->getTransport();
        if (!empty($templateVars['order'])) {
            $order = $templateVars['order'];
            foreach ($order->getAllItems() as $item) {
                $data = $this->helper->createPdfFile($item, $order->getId());
                if (!empty($data) && !empty($data['filename']) && !empty($data['pdfFile'])) {
                    // adds attachment in mail
                    $attachmentPart = $this->transportBuilder->addAttachment(
                        $this->reader->fileGetContents($data['pdfFile']),
                        $data['filename'],
                        'application/pdf'
                    );

                    $message = $transport->getMessage();
                    $body = \Zend\Mail\Message::fromString($message->getRawMessage())->getBody();
                    $body = \Zend_Mime_Decode::decodeQuotedPrintable($body);
                    $html = '';

                    if ($body instanceof \Zend\Mime\Message) {
                        $html = $body->generateMessage(\Zend\Mail\Headers::EOL);
                    } elseif ($body instanceof \Magento\Framework\Mail\MimeMessage) {
                        $html = (string) $body->getMessage();
                    } elseif ($body instanceof \Magento\Framework\Mail\EmailMessage) {
                        $html = (string) $body->getBodyText();
                    } else {
                        $html = (string) $body;
                    }

                    $htmlPart = new \Zend\Mime\Part($html);
                    $htmlPart->setCharset('utf-8');
                    $htmlPart->setEncoding(\Zend_Mime::ENCODING_QUOTEDPRINTABLE);
                    $htmlPart->setDisposition(\Zend_Mime::DISPOSITION_INLINE);
                    $htmlPart->setType(\Zend_Mime::TYPE_HTML);
                    $parts = [$htmlPart, $attachmentPart];

                    $bodyPart = new \Zend\Mime\Message();
                    $bodyPart->setParts($parts);
                    $message->setBody($bodyPart);
                }
            }
        }

        $transport->sendMessage();
    }
}
