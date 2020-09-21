<?php

/*
    HelpRealm (dnyHelpRealm) developed by Daniel Brendel

    (C) 2019 - 2020 by Daniel Brendel

     Version: 1.0
    Contact: dbrendel1988<at>gmail<dot>com
    GitHub: https://github.com/danielbrendel/

    Released under the MIT license
*/

namespace App;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use App\TicketThreadModel;
use App\TicketModel;
use App\MailerModel;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;

/**
 * Class MailserviceModel
 *
 * Perform ticket emailing operations
 */
class MailserviceModel extends Model
{
    private $clientMgr = null;
    private $client = null;

    /**
     * Construct and connect
     *
     * @return void
     */
    public function __construct()
    {
        $this->clientMgr = new ClientManager(__DIR__ . '/../config/imapconfig.php');
        $this->client = $this->clientMgr->account('default');
        $this->client->connect();
    }

    /**
     * Convert upload_max_filesize to byte value
     */
    public function iniFileSize()
    {
        $value = ini_get('upload_max_filesize');

        if (is_numeric($value)) {
            return $value;
        }

        $lastChar = strtolower(substr($value, -1));
        $actValue = intval(substr($value, 0, strlen($value)-1));

        if ($lastChar === 'k') {
            return $actValue * 1024;
        } else if ($lastChar === 'm') {
            return $actValue * 1024 * 1024;
        } else if ($lastChar === 'g') {
            return $actValue * 1024 * 1024 * 1024;
        }

        return $actValue;
    }

    /**
     * Process inbox. Create thread from message and then delete the message
     *
     * @return array The result of processed items
     */
    public function processInbox()
    {
        $resultArray = array();
        if ($this->client !== null) {
            $folders = $this->client->getFolders();
            foreach ($folders as $folder) {
                if ($folder->name == env('MAILSERV_INBOXNAME')) {
                    $mailmessages = $folder->messages()->all()->get();

                    foreach($mailmessages as $message){
                        $subject = $message->getSubject();
                        $idPos = strpos($subject, '[ID:');
                        if ($idPos !== false) {
                            $ticketHash = '';
                            for ($i = $idPos + 4; $i < strlen($subject); $i++) {
                                if ($subject[$i] === ']') {
                                    break;
                                }

                                $ticketHash .= $subject[$i];
                            }

                            $ticket = TicketModel::where('hash', '=', $ticketHash)->where('status', '<>', 3)->first();
                            if ($ticket !== null) {
                                $resultArrItem = array();
                                $resultArrItem['ticket'] = $ticket->id;

                                $sender = $message->getFrom()[0]->mail;
                                $ws = WorkSpaceModel::where('id', '=', $ticket->workspace)->first();
                                $isAgent = AgentModel::where('email', '=', $sender)->where('workspace', '=', $ws->id)->first();

                                $resultArrItem['workspace'] = $ws->id;
                                $resultArrItem['sender'] = $sender;
                                $resultArrItem['subject'] = $subject;

                                if (($isAgent === null) && ($ticket->confirmation !== '_confirmed')) {
                                    $ticket->confirmation = '_confirmed';
                                    $ticket->status = 1;
                                    $ticket->save();
                                    $message->delete();
                                    $resultArrItem['_confirm'] = true;
                                    if ($ws !== null) {
                                        $htmlCode = view('mail.ticket_confirmed_email')->render();
                                        MailerModel::sendMail($ticket->email, '[ID:' . $ticket->hash .  '][' . $ws->company . '] ' . substr(__('app.ticket_customer_confirm_success'), 0, 15), $htmlCode);
                                    }
                                    $resultArray[] = $resultArrItem;
                                    continue;
                                }

                                if ($isAgent !== null) {
                                    $ticket->status = 2;
                                    $ticket->save();
                                } else {
                                    $ticket->status = 1;
                                    $ticket->save();
                                }

                                $thread = new TicketThreadModel;
                                $thread->user_id = ($isAgent !== null) ? $isAgent->user_id : 0;
                                $thread->ticket_id = $ticket->id;
                                $thread->text = $message->getTextBody();
                                $thread->save();

                                $resultArrItem['message'] = $thread->text;
                                $resultArrItem['user_id'] = $thread->user_id;
                                $resultArrItem['attachments'] = array();

                                $attachments = $message->getAttachments();
                                foreach ($attachments as $file) {
                                    if ($file->getSize() <= $this->iniFileSize()) {
                                        $bIgnoreFile = false;

                                        if (strlen($ws->extfilter) > 0) {
                                            foreach (explode(' ', $ws->extfilter) as $fileext) {
                                                $fileext = str_replace('.', '', trim($fileext));
                                                if ($file->getExtension() === $fileext) {
                                                    $bIgnoreFile = true;
                                                    break;
                                                }
                                            }
                                        }

                                        if ($bIgnoreFile) {
                                            continue;
                                        }

                                        $newName = $file->getName() . md5(random_bytes(55)) . '.' . $file->getExtension();
                                        $file->save(public_path() . '/uploads', $newName);

                                        $ticketFile = new TicketsHaveFiles();
                                        $ticketFile->ticket_hash = $ticket->hash;
                                        $ticketFile->file = $newName;
                                        $ticketFile->save();

                                        $resultArrItem['attachments'][] = $newName;
                                    }
                                }

                                $message->delete();

                                $resultArray[] = $resultArrItem;

                                if ($ws !== null) {
                                    if ($isAgent !== null) {
                                        $htmlCode = view('mail.ticket_reply_agent', ['workspace' => $ws->name, 'name' => $ticket->name, 'hash' => $ticket->hash, 'agent' => $isAgent->surname . ' ' . $isAgent->lastname, 'message' => $message->getTextBody()])->render();
                                        MailerModel::sendMail($ticket->email, '[ID:' . $ticket->hash .  '][' . $ws->company . '] ' . __('app.mail_ticket_agent_replied'), $htmlCode);
                                    } else {
                                        $assignee = AgentModel::where('id', '=', $ticket->assignee)->first();
                                        if ($assignee !== null) {
                                            $htmlCode = view('mail.ticket_reply_customer', ['workspace' => $ws->name, 'name' => $assignee->surname . ' ' . $assignee->lastname, 'id' => $ticket->id, 'customer' => $ticket->name, 'message' => $message->getTextBody()])->render();
                                            MailerModel::sendMail($assignee->email, '[ID:' . $ticketHash . '][' . $ws->company . '] ' . __('app.mail_ticket_customer_replied'), $htmlCode);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return $resultArray;
    }

    /**
     * Process workspace inboxes.
     *
     * @return array The result of processed items
     */
    public static function processWorkspaceInboxes()
    {
        $resultArr = array();

        $workspaces = WorkSpaceModel::where('mailer_useown', '=', true)->get();
        foreach ($workspaces as $workspace) {
            try {
                $_ENV['SMTP_HOST'] = $workspace->mailer_host_smtp;
                $_ENV['SMTP_PORT'] = $workspace->mailer_port_smtp;
                $_ENV['MAILSERV_HOST'] = $workspace->mailer_host_imap;
                $_ENV['MAILSERV_PORT'] = $workspace->mailer_port_imap;
                $_ENV['MAILSERV_INBOXNAME'] = $workspace->mailer_inbox;
                $_ENV['SMTP_FROMADDRESS'] = $workspace->mailer_address;
                $_ENV['MAILSERV_EMAILADDR'] = $workspace->mailer_address;
                $_ENV['SMTP_FROMNAME'] = $workspace->mailer_fromname;
                $_ENV['SMTP_USERNAME'] = $workspace->mailer_username;
                $_ENV['MAILSERV_USERNAME'] = $workspace->mailer_username;
                $_ENV['SMTP_PASSWORD'] = $workspace->mailer_password;
                $_ENV['MAILSERV_PASSWORD'] = $workspace->mailer_password;
                $_ENV['APP_NAME'] = $workspace->company;

                $mailer = new self();
                $data = $mailer->processInbox();

                $resultArr[] = array('workspace' => $workspace->id, 'data' => $data);
            } catch (\Exception $e) {
                $resultArr[] = array('workspace' => $workspace->id, 'error' => $e->getCode(), 'data' => $e->getMessage());
            }
        }

        return $resultArr;
    }
}
