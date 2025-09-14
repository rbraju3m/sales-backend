<?php

namespace Modules\Hospital\App\Entities;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Invoice
 *
 * @ORM\Table(name="hms_invoice_patient_referred")
 * @ORM\Entity()
 */
class InvoicePatientReferred
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;


    /**
     * @ORM\ManyToOne(targetEntity="Config", cascade={"detach","merge"})
     * @ORM\JoinColumn(name="config_id", referencedColumnName="id", onDelete="CASCADE")
     */
    protected $config;

    /**
     * @ORM\ManyToOne(targetEntity="Modules\Core\App\Entities\User")
     * @ORM\JoinColumn(name="created_by_id", referencedColumnName="id", nullable=true)
     **/
    private  $createdBy;

    /**
     * @ORM\OneToOne(targetEntity="Invoice", cascade={"detach","merge"})
     * @ORM\JoinColumn(name="hms_invoice_id", referencedColumnName="id", onDelete="CASCADE")
     */
    private $invoice;

    /**
     * @ORM\ManyToOne(targetEntity="Prescription", cascade={"detach","merge"})
     * @ORM\JoinColumn(name="prescription_id", referencedColumnName="id", onDelete="CASCADE")
     */
    private $prescription;

    /**
     * @ORM\ManyToOne(targetEntity="Particular")
     **/
    private  $assignDoctor;

     /**
     * @ORM\ManyToOne(targetEntity="Particular")
     **/
    private  $assignReferredDoctor;

    /**
     * @ORM\ManyToOne(targetEntity="Particular")
     **/
    private  $opdRoom;

    /**
     * @ORM\ManyToOne(targetEntity="Particular")
     **/
    private  $opdDoctor;

    /**
     * @var string
     *
     * @ORM\Column(name="comment", type="text", nullable=true)
     */
    private $comment;

    /**
     * @var string
     *
     * @ORM\Column(name="hospital", type="string", nullable=true)
     */
    private $hospital;

    /**
     * @var $jsonContent
     *
     * @ORM\Column( type="json",nullable = true)
     */
    private $jsonContent;


    /**
     * @var \DateTime
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created_at", type="datetime")
     */
    private $createdAt;

    /**
     * @var \DateTime
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="updated_at", type="datetime")
     */
    private $updatedAt;


    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

}

