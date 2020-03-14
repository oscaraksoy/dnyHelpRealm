<?php

/*
    HelpRealm (dnyHelpRealm) developed by Daniel Brendel

    (C) 2019 - 2020 by Daniel Brendel

    Version: 0.1
    Contact: dbrendel1988<at>gmail<dot>com
    GitHub: https://github.com/danielbrendel/

    Released under the MIT license
*/

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Auth;
use \App\User;
use \App\AgentModel;
use \App\GroupsModel;
use \App\TicketModel;
use \App\TicketThreadModel;
use \App\TicketsHaveFiles;
use \App\AgentsHaveGroups;
use \App\PushModel;
use \App\CaptchaModel;
use \App\BgImagesModel;
use \App\WorkSpaceModel;
use \App\TicketsHaveTypes;

/**
 * Class TicketController
 * 
 * Perform ticket related computations
 */
class TicketController extends Controller
{
    /**
     * Show ticket list
     * 
     * @param string $workspace
     * @return mixed
     */
    public function viewTicketList($workspace)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        $tickets = TicketModel::queryAgentTickets(User::getAgent(auth()->id())->id);

        $groups = array();
        foreach ($tickets as $ticket)
        {
            $item = array();
            $item['ticket_id'] = $ticket->id;
            $item['group_name'] = GroupsModel::get($ticket->group)->name;
            $item['ticket_type'] = TicketsHaveTypes::where('workspace', '=', $ws->id)->where('id', '=', $ticket->type)->first();
            array_push($groups, $item);
        }

        $groupsofagent = AgentsHaveGroups::where('agent_id', '=', User::getAgent(auth()->id())->id)->get();
        $grouptickets = array();
        foreach ($groupsofagent as $grp) {
            $gtcur = array();
            $gtcur['group'] = GroupsModel::where('id', '=', $grp->group_id)->first();
            $gtcur['tickets'] = TicketModel::where('group', '=', $grp->group_id)->orderBy('updated_at', 'desc')->get();
            array_push($grouptickets, $gtcur);
        }

        $attr = [
            'workspace' => $ws->name,
            'location' => __('app.ticket_list'),
            'user' => User::get(auth()->id()),
            'tickets' => $tickets,
            'grouptickets' => $grouptickets,
            'groups' => $groups,
            'superadmin' => User::getAgent(auth()->id())->superadmin
        ];

        return view('ticket.list', $attr);
    }

    /**
     * Show ticket details
     * 
     * @param string $workspace
     * @param $id
     * @return mixed
     */
    public function viewShowTicketAgent($workspace, $id)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        $ticket = TicketModel::where('id', '=', $id)->where('workspace', '=', $ws->id)->first();
        $assignee = AgentModel::queryAgent($ticket->assignee);

        $ticketFileInfo = array();
        $ticketFiles = TicketsHaveFiles::where('ticket_hash', '=', $ticket->hash)->get();
        foreach ($ticketFiles as $tf) {
            if (file_exists(base_path() . '/public/uploads/' . $tf->file)) {
                $entry['item'] = $tf;
                $entry['size'] = filesize(base_path() . '/public/uploads/' . $tf->file);
                $entry['ext'] = pathinfo(base_path() . '/public/uploads/' . $tf->file, PATHINFO_EXTENSION);
                array_push($ticketFileInfo, $entry);
            }
        }

        $location = __('app.ticket_id', ['id' => $id]);
        if (strlen(strval($id)) > 8) {
            $location = substr($location, 0, strlen($location) - strlen(strval($id)) + 8) . '...';
        }

        $attr = [
            'workspace' => $ws->name,
            'location' => $location,
            'fulllocation' => __('app.ticket_id', ['id' => $id]),
            'user' => User::get(auth()->id()),
            'ticket' => $ticket,
            'ticketType' => TicketsHaveTypes::where('workspace', '=', $ws->id)->where('id', '=', $ticket->type)->first(),
            'ticketTypes' => TicketsHaveTypes::where('workspace', '=', $ws->id)->get(),
            'thread' => TicketThreadModel::where('ticket_id', '=', $id)->orderBy('id', 'desc')->get(),
            'group' => GroupsModel::get($ticket->group)->name,
            'agent' => $assignee,
            'agents' => AgentModel::where('active', '=', true)->where('workspace', '=', $ws->id)->get(),
            'groups' => GroupsModel::where('workspace', '=', $ws->id)->get(),
            'files' => $ticketFileInfo,
            'superadmin' => User::getAgent(auth()->id())->superadmin,
            'notifications' => PushModel::getUnseenNotifications(auth()->id())
        ];

        $attr['threaddata'] = array();

        foreach ($attr['thread'] as $th) {
            $entry = array();
            $entry['thread_id'] = $th->id;
            $entry['user_id'] = $th->user_id;
            if ($th->user_id === 0) {
                $entry['name'] = $ticket->name;
                $entry['avatar'] = 'https://www.gravatar.com/avatar/' . md5($ticket->email) . '?d=identicon';
            } else {
                $user = User::get($th->user_id);
                $entity = User::getAgent($user->id);
                $entry['name'] = $entity->surname . ' ' . $entity->lastname;
                $entry['avatar'] = asset('/gfx/avatars/' . $user->avatar);
            }
            array_push($attr['threaddata'], $entry);
        }

        return view('ticket.agent_show', $attr);
    }

    /**
     * Show ticket details
     * 
     * @param string $workspace
     * @param $hash
     * @return mixed
     */
    public function viewShowTicketClient($workspace, $hash)
    {
        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        \App::setLocale($ws->lang);

        $img = BgImagesModel::queryRandomImage($ws->id);
        $captchadata = CaptchaModel::createSum(session()->getId());

        $ticket = TicketModel::where('hash', '=', $hash)->where('workspace', '=', $ws->id)->first();
        if ($ticket === null) {
            return back()->with('error', __('app.ticket_not_found'));
        }

        $showConfirmSuccessMsg = false;
        $token = request('confirmation');
        if (($token !== null) && ($ticket->confirmation !== '_confirmed')) {
            if ($token !== $ticket->confirmation) {
                return back()->with('error', __('app.ticket_invalid_confirmation'));
            } else {
                $ticket->confirmation = '_confirmed';
                $ticket->status = 1;
                $ticket->save();

                $showConfirmSuccessMsg = true;
            }
        }

        if ($ticket->confirmation !== '_confirmed') {
            return back()->with('error', __('app.ticket_not_confirmed'));
        }

        $assignee = AgentModel::queryAgent($ticket->assignee);

        $ticketFileInfo = array();
        $ticketFiles = TicketsHaveFiles::where('ticket_hash', '=', $ticket->hash)->get();
        foreach ($ticketFiles as $tf) {
            if (file_exists(base_path() . '/public/uploads/' . $tf->file)) {
                $entry['item'] = $tf;
                $entry['size'] = filesize(base_path() . '/public/uploads/' . $tf->file);
                $entry['ext'] = pathinfo(base_path() . '/public/uploads/' . $tf->file, PATHINFO_EXTENSION);
                array_push($ticketFileInfo, $entry);
            }
        }

        $captchadata = CaptchaModel::createSum(session()->getId());

        $attr = [
            'workspace' => $ws->name,
            'wsobject' => $ws,
            'location' => __('app.ticket_list'),
            'user' => User::get(auth()->id()),
            'ticket' => $ticket,
            'ticketType' => TicketsHaveTypes::where('workspace', '=', $ws->id)->where('id', '=', $ticket->type)->first(),
            'thread' => TicketThreadModel::where('ticket_id', '=', $ticket->id)->orderBy('id', 'desc')->get(),
            'group' => GroupsModel::get($ticket->group)->name,
            'agent' => $assignee,
            'agents' => AgentModel::where('active', '=', '1')->get(),
            'groups' => GroupsModel::all(),
            'files' => $ticketFileInfo,
            'isclosed' => $ticket->status === 3,
            'captchadata' => $captchadata
        ];

        $attr['threaddata'] = array();

        foreach ($attr['thread'] as $th) {
            $entry = array();
            $entry['thread_id'] = $th->id;
            $entry['user_id'] = $th->user_id;
            if ($th->user_id === 0) {
                $entry['name'] = $ticket->name;
                $entry['avatar'] = 'https://www.gravatar.com/avatar/' . md5($ticket->email) . '?d=identicon';
            } else {
                $user = User::get($th->user_id);
                $entity = User::getAgent($user->id);
                $entry['name'] = $entity->surname . ' ' . $entity->lastname;
                $entry['avatar'] = asset('/gfx/avatars/' . $user->avatar);
            }
            array_push($attr['threaddata'], $entry);
        }

        $attr['bgimage'] = $img;
        $attr['captchadata'] = $captchadata;

        if ($showConfirmSuccessMsg === true) {
            session()->flash('success', __('app.ticket_customer_confirm_success'));
		}

        return view('ticket.customer_show', $attr);
    }

    /**
     * Create new ticket
     * 
     * @param string $workspace
     * @return Illuminate\Http\RedirectResponse
     */
    public function createTicket($workspace)
    {
        $attr = request()->validate([
            'subject' => 'required|min:5',
            'text' => 'required|max:4096',
            'name' => 'required',
            'email' => 'required|email',
            'type' => 'required|numeric|min:1',
            'prio' => 'required|numeric|min:1|max:3',
            'captcha' => 'required|numeric'
        ]);

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        \App::setLocale($ws->lang);

        if ($attr['captcha'] !== CaptchaModel::querySum(session()->getId())) {
            return back()->withInput()->with('error', __('app.ticket_invalid_captcha'));
        }

        $hasType = TicketsHaveTypes::where('workspace', '=', $ws->id)->where('id', '=', $attr['type'])->first();
        if ($hasType === null) {
            return back()->with('error', __('app.ticket_type_not_found'));
        }

        $attr['workspace'] = $ws->id;

        $attr['assignee'] = 0;
        $attr['group'] = GroupsModel::getPrimaryGroup($ws->id)->id;

        $attr['hash'] = md5($attr['name'] . $attr['email'] . date('Y-m-d h:i:s') . random_bytes(55));
        $attr['address'] = $_SERVER['REMOTE_ADDR'];

        $attr['confirmation'] = md5($attr['hash'] . random_bytes(55));
        $attr['status'] = 0;

        $ticketOfAddress = TicketModel::where('address', '=', $attr['address'])->orderBy('created_at', 'desc')->first();
        if ($ticketOfAddress !== null) {
            $tmNow = Carbon::now();
            $tmLast = Carbon::createFromFormat('Y-m-d H:i:s', $ticketOfAddress->created_at);
            $diff = $tmLast->diffInSeconds($tmNow);
            if ($diff < 60) {
                return back()->withInput()->with('error', __('app.ticket_wait_time', ['remaining' => $diff]));
            }
        }

        $data = TicketModel::create($attr);
        if ($data) {
            $htmlCode = view('mail.ticket_create', ['workspace' => $ws->name, 'name' => $attr['name'], 'hash' => $data->hash, 'confirmation' => $attr['confirmation']])->render();

            @mail($attr['email'], '[' . $ws->company . '] ' . __('app.mail_ticket_creation'), wordwrap($htmlCode, 70), 'Content-type: text/html; charset=utf-8' . "\r\n");

            $agentsInGroup = AgentsHaveGroups::where('group_id', '=', $attr['group'])->get();
            foreach ($agentsInGroup as $entry) {
                $agentOfGroup = AgentModel::where('id', '=', $entry->agent_id)->where('workspace', '=', $ws->id)->where('mailonticketingroup', '=', true)->first();
                if ($agentOfGroup !== null) {
                    $htmlCode = view('mail.ticket_in_group', ['workspace' => $ws->name, 'name' => $agentOfGroup->surname . ' ' . $agentOfGroup->lastname, 'ticketid' => $data->id])->render();
                    @mail($agentOfGroup->email, '[' . $ws->company . '] ' . __('app.mail_ticket_in_group'), wordwrap($htmlCode, 70), 'Content-type: text/html; charset=utf-8' . "\r\n");
                }
            }

            return back()->with('success', __('app.ticket_created_customer'));
        } else {
            return back()->withInput()->with('error', __('app.ticket_creation_failed'));
        }
    }

    /**
     * Create new ticket
     * 
     * @param string $workspace
     * @return Illuminate\Http\RedirectResponse
     */
    public function createTicketAgent($workspace)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }
        
        $attr = request()->validate([
            'subject' => 'required|min:5',
            'text' => 'required|max:4096',
            'name' => 'required',
            'email' => 'required|email',
            'type' => 'required|numeric|min:1',
            'prio' => 'required|numeric|min:1|max:3',
            'group' => 'required',
            'assignee' => 'required'
        ]);

        $hasType = TicketsHaveTypes::where('workspace', '=', $ws->id)->where('id', '=', $attr['type'])->first();
        if ($hasType === null) {
            return back()->with('error', __('app.ticket_type_not_found'));
        }

        $attr['workspace'] = $ws->id;
        $attr['hash'] = md5($attr['name'] . $attr['email'] . date('Y-m-d h:i:s') . random_bytes(10));
        $attr['confirmation'] = md5($attr['hash'] . random_bytes(55));
        $attr['status'] = 0;
        $attr['address'] = $_SERVER['REMOTE_ADDR'];
        
        $data = TicketModel::create($attr);
        if ($data) {
            $htmlCode = view('mail.ticket_create', ['workspace' => $ws->name, 'name' => $attr['name'], 'hash' => $data->hash, 'confirmation' => $attr['confirmation']])->render();

            @mail($attr['email'], '[' . $ws->company . '] ' . __('app.mail_ticket_creation'), wordwrap($htmlCode, 70), 'Content-type: text/html; charset=utf-8' . "\r\n");

            return redirect('/' . $ws->name . '/ticket/' . $data->id . '/show/')->with('success', __('app.ticket_created'));
        } else {
            return back()->withInput()->with('error', __('app.ticket_creation_failed'));
        }
    }

    /**
     * Show view for agents ticket creation
     * 
     * @param string $workspace
     * @return mixed
     */
    public function viewCreateTicket($workspace)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        $attr = [
            'workspace' => $ws->name,
            'location' => __('app.ticket_create'),
            'user' => User::get(auth()->id()),
            'superadmin' => User::getAgent(auth()->id())->superadmin,
            'groups' => GroupsModel::where('workspace', '=', $ws->id)->get(),
            'agents' => AgentModel::where('active', '=', true)->where('workspace', '=', $ws->id)->get(),
            'ticketTypes' => TicketsHaveTypes::where('workspace', '=', $ws->id)->get()
        ];

        return view('ticket.create', $attr);
    }

    /**
     * Delete ticket
     * 
     * @param string $workspace
     * @param int $id The ID of the ticket
     * @return Illuminate\Http\RedirectResponse
     */
    public function deleteTicket($workspace, $id)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        if (!$agent->superadmin) {
            $ingroup = AgentsHaveGroups::where('agent_id', '=', $agent->id)->where('group_id', '=', $ticket->group)->first();
            if (!$ingroup) {
                return back()->with('error', __('app.ticket_not_group_member'));
            }    
        }

        $ticket = TicketModel::where('id', '=', $id)->where('workspace', '=', $ws->id)->first();
        if ($ticket) {
            $files = TicketsHaveFiles::where('ticket_id', '=', $ticket->id)->get();

            foreach ($files as $file) {
                unlink('public/uploads/' . $file->file);
                $file->delete();
            }

            $ticket->delete();

            return back()->with('success', __('app.ticket_deleted'));
        } else {
            return back()->with('error', __('app.ticket_not_found'));
        }
    }

    /**
     * Edit ticket data
     * 
     * @param string $workspace
     * @param int $id The ticket ID
     * @return Illuminate\Http\RedirectResponse
     */
    public function editTicket($workspace, $id)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        $agent = AgentModel::getAgent(User::get(auth()->id())->user_id);
        if (!$agent) {
            return back()->with('error', __('app.agent_not_found'));
        }
        
        if (!$agent->superadmin) {
            $ingroup = AgentsHaveGroups::where('agent_id', '=', $agent->id)->where('group_id', '=', $ticket->group)->first();
            if (!$ingroup) {
                return back()->with('error', __('app.ticket_not_group_member'));
            }    
        }

        $attr = request()->validate([
            'subject' => 'min:5',
            'text' => 'max:4096',
            'client' => 'numeric',
            'type' => 'numeric|min:1|max:3',
            'status' => 'numeric|min:1|max:3',
            'group' => 'numeric',
            'prio' => 'numeric|min:1|max:3',
            'assignee' => 'numeric',
        ]);

        $ticket = TicketModel::where('id', '=', $id)->where('workspace', '=', $ws->id)->first();
        if ($ticket) {
            if (isset($attr['subject'])) $ticket->subject = $attr['subject'];
            if (isset($attr['text'])) $ticket->subject = $attr['text'];
            if (isset($attr['client'])) $ticket->subject = $attr['client'];
            if (isset($attr['type'])) $ticket->subject = $attr['type'];
            if (isset($attr['status'])) $ticket->subject = $attr['status'];
            if (isset($attr['group'])) $ticket->subject = $attr['group'];
            if (isset($attr['prio'])) $ticket->prio = $attr['prio'];
            if (isset($attr['assignee'])) $ticket->subject = $attr['assignee'];
            $ticket->save();

            return back()->with('success', __('app.ticket_data_stored'));
        } else {
            return back()->with('error', __('app.ticket_not_found'));
        }
    }

    /**
     * Assign ticket to group
     * 
     * @param string $workspace
     * @param int $ticket The ticket ID
     * @param int $agent The agent ID
     * @return Illuminate\Http\RedirectResponse
     */
    public function assignToAgent($workspace, $ticket, $agent)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        $ag = AgentModel::where('id', '=', $agent)->where('workspace', '=', $ws->id)->first();
        if (!$ag) {
            return back()->with('error', __('app.agent_not_found'));
        }

        $record = TicketModel::where('id', '=', $ticket)->where('workspace', '=', $ws->id)->first();
        if ($record) {
            $record->assignee = $agent;
            $record->save();

            $htmlCode = view('mail.ticket_assign', ['workspace' => $ws->name, 'name' => $ag->surname . ' ' . $ag->lastname, 'id' => $ticket])->render();
            @mail($ag->email, '[' . $ws->company . '] ' . __('app.mail_ticket_assigned'), wordwrap($htmlCode, 70), 'Content-type: text/html; charset=utf-8' . "\r\n");

            return back()->with('success', __('app.ticket_agent_assigned'));
        } else {
            return back()->with('error', __('app.ticket_not_found'));
        }
    }

    /**
     * Assign ticket to group
     * 
     * @param string $workspace
     * @param int $ticket The ticket ID
     * @param int $group The group ID
     * @return Illuminate\Http\RedirectResponse
     */
    public function assignToGroup($workspace, $ticket, $group)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        $groupData = GroupsModel::where('id', '=', $group)->where('workspace', '=', $ws->id)->first();
        if ($groupData === null) {
            return back()->with('error', __('app.group_not_found'));
        }

        $record = TicketModel::where('id', '=', $ticket)->where('workspace', '=', $ws->id)->first();
        if ($record) {
            $record->group = $group;
            $record->save();

            $agentsInGroup = AgentsHaveGroups::where('group_id', '=', $group)->get();
            foreach ($agentsInGroup as $entry) {
                $agentOfGroup = AgentModel::where('id', '=', $entry->agent_id)->where('workspace', '=', $ws->id)->where('mailonticketingroup', '=', true)->first();
                if ($agentOfGroup !== null) {
                    $htmlCode = view('mail.ticket_in_group', ['workspace' => $ws->name, 'name' => $agentOfGroup->surname . ' ' . $agentOfGroup->lastname, 'ticketid' => $record->id])->render();
                    
                    @mail($agentOfGroup->email, '[' . $ws->company . '] ' . __('app.mail_ticket_in_group'), wordwrap($htmlCode, 70), 'Content-type: text/html; charset=utf-8' . "\r\n");
                }
            }
            
            return back()->with('success', __('app.ticket_group_assigned'));
        } else {
            return back()->with('error', __('app.ticket_not_found'));
        }
    }

    /**
     * Set ticket status
     * 
     * @param string $workspace
     * @param int $id The ticket ID
     * @param int $status The new ticket status
     * @return Illuminate\Http\RedirectResponse
     */
    public function setStatus($workspace, $id, $status)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        if ($status < 1) $status = 1;
        if ($status > 3) $status = 3;

        $ticket = TicketModel::where('id', '=', $id)->where('workspace', '=', $ws->id)->first();
        if ($ticket) {
            $ticket->status = $status;
            $ticket->save();

            if ($status == 3) {
                $htmlCode = view('mail.ticket_closed', ['workspace' => $ws->name, 'name' => $ticket->name, 'hash' => $ticket->hash])->render();

                @mail($ticket->email, '[' . $ws->company . '] ' . __('app.mail_ticket_closed'), wordwrap($htmlCode, 70), 'Content-type: text/html; charset=utf-8' . "\r\n");
            }

            return back()->with('success', __('app.ticket_status_changed'));
        } else {
            return back()->with('error', __('app.ticket_not_found'));
        }
    }

    /**
     * Set ticket type
     * 
     * @param string $workspace
     * @param int $id The ticket ID
     * @param int $type The new ticket type
     * @return Illuminate\Http\RedirectResponse
     */
    public function setType($workspace, $id, $type)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        $hasType = TicketsHaveTypes::where('workspace', '=', $ws->id)->where('id', '=', $type)->first();
        if ($hasType === null) {
            return back()->with('error', __('app.ticket_type_not_found'));
        }

        $ticket = TicketModel::where('id', '=', $id)->where('workspace', '=', $ws->id)->first();
        if ($ticket) {
            $ticket->type = $type;
            $ticket->save();

            return back()->with('success', __('app.ticket_type_changed'));
        } else {
            return back()->with('error', __('app.ticket_not_found'));
        }
    }

    /**
     * Set ticket priority
     * 
     * @param string $workspace
     * @param int $id The ticket ID
     * @param int $prio The new priority
     * @return Illuminate\Http\RedirectResponse
     */
    public function setPriority($workspace, $id, $prio)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        if ($prio < 1) $prio = 1;
        if ($prio > 3) $prio = 3;

        $ticket = TicketModel::where('id', '=', $id)->where('workspace', '=', $ws->id)->first();
        if ($ticket) {
            $ticket->prio = $prio;
            $ticket->save();

            return back()->with('success', __('app.ticket_prio_changed'));
        } else {
            return back()->with('error', __('app.ticket_not_found'));
        }
    }

    /**
     * Add comment to ticket
     * 
     * @param string $workspace
     * @param int $id The ticket ID
     * @return Illuminate\Http\RedirectResponse
     */
    public function addCommentAgent($workspace, $id)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        $ticket = TicketModel::where('id', '=', $id)->where('workspace', '=', $ws->id)->first();
        if (!$ticket) {
            return back()->with('error', __('app.ticket_not_found'));
        }

        $agent = AgentModel::where('user_id', '=', auth()->id())->where('workspace', '=', $ws->id)->first();
        if (!$agent) {
            return back()->with('error', __('app.agent_permission_required'));
        }

        if (!$agent->superadmin) {
            $ingroup = AgentsHaveGroups::where('agent_id', '=', $agent->id)->where('group_id', '=', $ticket->group)->first();
            if (!$ingroup) {
                return back()->with('error', __('app.ticket_not_group_member'));
            }    
        }

        $attr = request()->validate([
            'text' => 'required|max:4096'
        ]);

        $sender = User::getAgent(auth()->id());

        $attr['ticket_id'] = $id;
        $attr['user_id'] = auth()->id();

        $data = TicketThreadModel::create($attr);
        if ($data) {
            $ticket->status = 2;
            $ticket->save();

            $htmlCode = view('mail.ticket_reply_agent', ['workspace' => $ws->name, 'name' => $ticket->name, 'hash' => $ticket->hash])->render();

            @mail($ticket->email, '[' . $ws->company . '] ' . __('app.mail_ticket_agent_replied'), wordwrap($htmlCode, 70), 'Content-type: text/html; charset=utf-8' . "\r\n");

            return back()->with('success', __('app.ticket_comment_added'));
        } else {
            return back()->with('error', __('app.ticket_comment_add_failure'));
        }
    }

    /**
     * Add comment to ticket
     * 
     * @param string $workspace
     * @param int $id The ticket ID
     * @return Illuminate\Http\RedirectResponse
     */
    public function addCommentCustomer($workspace, $id)
    {
        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        \App::setLocale($ws->lang);

        $ticket = TicketModel::where('id', '=', $id)->where('workspace', '=', $ws->id)->first();
        if (!$ticket) {
            return back()->with('error', __('app.ticket_not_found'));
        }

        if ($ticket->confirmation !== '_confirmed') {
            return back()->with('error', __('app.ticket_not_confirmed'));
        }

        if ($ticket->status === 3) {
            return back()->with('error', __('app.ticket_closed'));
        }

        $attr = request()->validate([
            'text' => 'required|max:4096',
            'captcha' => 'required|numeric'
        ]);
        
        if ($attr['captcha'] !== CaptchaModel::querySum(session()->getId())) {
            return back()->with('error', __('app.ticket_invalid_captcha'));
        }
        
        $attr['ticket_id'] = $id;
        $attr['user_id'] = 0;

        $data = TicketThreadModel::create($attr);
        if ($data) {
            $updTicket = TicketModel::where('id', '=', $id)->first();
            $updTicket->status = 1;
            $updTicket->save();

            $assignee = AgentModel::where('id', '=', $ticket->assignee)->first();
            if ($assignee != null) {
                $htmlCode = view('mail.ticket_reply_customer', ['workspace' => $ws->name, 'name' => $assignee->surname . ' ' . $assignee->lastname, 'id' => $updTicket->id])->render();

                @mail($assignee->email, '[' . $ws->company . '] Ticket reply', wordwrap($htmlCode, 70), 'Content-type: text/html; charset=utf-8' . "\r\n");
            
                PushModel::addNotification('Ticket reply', $updTicket->name . ' has replied to your ticket #' . $ticket->id . ' ' . $ticket->subject, $assignee->user_id);
            }

            return back();
        } else {
            return back()->with('error', __('app.ticket_comment_add_failure'));
        }
    }

    /**
     * Edit comment
     * 
     * @param string $workspace
     * @param int $id The ticket ID
     * @param int $cmt The comment ID
     * @return Illuminate\Http\RedirectResponse
     */
    public function editComment($workspace, $id, $cmt)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        $ticket = TicketModel::where('id', '=', $id)->where('workspace', '=', $ws->id)->first();
        if (!$ticket) {
            return back()->with('error', __('app.ticket_not_found'));
        }

        $attr = request()->validate([
            'text' => 'required|max:4096'
        ]);

        $comment = TicketThreadModel::where('ticket_id', '=', $id)->where('id', '=', $cmt)->where('user_id', '=', auth()->id())->first();
        if ($comment) {
            $comment->text = $attr['text'];
            $comment->save();

            $updTicket = TicketModel::where('id', '=', $id)->where('workspace', '=', $ws->id)->first();
            $updTicket->touch();

            return back()->with('success', __('app.ticket_comment_edited'));
        } else {
            return back()->with('error', __('app.ticket_comment_not_found'));
        }
    }

    /**
     * Edit comment
     * 
     * @param string $workspace
     * @param int $id The ticket ID
     * @param int $cmt The comment ID
     * @return Illuminate\Http\RedirectResponse
     */
    public function editCommentCustomer($workspace, $id, $cmt)
    {
        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        \App::setLocale($ws->lang);

        $ticket = TicketModel::where('id', '=', $id)->where('workspace', '=', $ws->id)->first();
        if (!$ticket) {
            return back()->with('error', __('app.ticket_not_found'));
        }

        $attr = request()->validate([
            'text' => 'required|max:4096'
        ]);

        $comment = TicketThreadModel::where('ticket_id', '=', $id)->where('id', '=', $cmt)->where('user_id', '=', 0)->first();
        if ($comment) {
            $comment->text = $attr['text'];
            $comment->save();

            $updTicket = TicketModel::where('id', '=', $id)->where('workspace', '=', $ws->id)->first();
            $updTicket->touch();

            return back()->with('success', __('app.ticket_comment_edited'));
        } else {
            return back()->with('error', __('app.ticket_comment_not_found'));
        }
    }

    /**
     * Delete comment
     * 
     * @param string $workspace
     * @param int $id The ticket ID
     * @param int $cmt The comment ID
     * @return Illuminate\Http\RedirectResponse
     */
    public function deleteComment($workspace, $id, $cmt)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        $agent = AgentModel::where('user_id', '=', auth()->id())->where('workspace', '=', $ws->id)->first();
        if (!$agent) {
            return back()->with('error', __('app.agent_permission_required'));
        }

        $ticket = TicketModel::where('id', '=', $id)->where('workspace', '=', $ws->id)->first();
        if (!$ticket) {
            return back()->with('error', __('app.ticket_not_found'));
        }

        if (!$agent->superadmin) {
            $ingroup = AgentsHaveGroups::where('agent_id', '=', $agent->id)->where('group_id', '=', $ticket->group)->first();
            if (!$ingroup) {
                return back()->with('error', __('app.ticket_not_group_member'));
            }    
        }

        $comment = TicketThreadModel::where('ticket_id', '=', $id)->where('id', '=', $cmt)->first();
        if ($comment) {
            $comment->delete();

            return back()->with('success', __('app.ticket_comment_deleted'));
        } else {
            return back()->with('error', __('app.ticket_comment_not_found'));
        }
    }

    /**
     * Add file to ticket
     * 
     * @param string $workspace
     * @param string $id The ticket hash
     * @return Illuminate\Http\RedirectResponse
     */
    public function addFile($workspace, $hash)
    {
        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        if (Auth::guest()) {
            \App::setLocale($ws->lang);
            $attr = request()->validate(['file' => 'file|required', 'captcha' => 'required|numeric']);
        } else {
            $attr = request()->validate(['file' => 'file|required']);
        }

        if ((Auth::guest()) && ($attr['captcha'] !== CaptchaModel::querySum(session()->getId()))) {
            return back()->with('error', __('app.ticket_invalid_captcha'));
        }

        $ticket = TicketModel::where('hash', '=', $hash)->where('workspace', '=', $ws->id)->first();

        if ($ticket === null) {
            return back()->with('error', __('app.ticket_not_found'));
        }

        if ((Auth::guest()) && ($ticket->confirmation !== '_confirmed')) {
            return back()->with('error', __('app.ticket_not_confirmed'));
        }

        if ($ticket->status === 3) {
            return back()->with('error', __('app.ticket_closed'));
        }

        $att = request()->file('file');
        if ($att != null) {
            $fname = $att->getClientOriginalName() . '_' . uniqid('', true) . '_' . md5(random_bytes(55));
            $fext = $att->getClientOriginalExtension();
            $att->move(public_path() . '/uploads', $fname . '.' . $fext);

            $dbstor = new TicketsHaveFiles();
            $dbstor->ticket_hash = $hash;
            $dbstor->file = $fname . '.' . $fext;
            $dbstor->save();

            if (!Auth::guest()) {
                return back()->with('success', __('app.ticket_file_attached'));
            } else {
                return back();
            }
        }

        return back()->with('error', 'app.ticket_no_file_given');
    }

    /**
     * Return ticket search view
     * 
     * @param string $workspace
     * @return Illuminate\View\View
     */
    public function viewSearch($workspace)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        return view('ticket.search', [
            'workspace' => $ws->name,
            'location' => __('app.ticket_search'),
            'user' => User::get(auth()->id()),
            'self' => User::getAgent(auth()->id()),
            'superadmin' => User::getAgent(auth()->id())->superadmin
        ]);
    }

    /**
     * Search for ticket
     * 
     * @param string $workspace
     * @return Illuminate\View\View
     */
    public function search($workspace)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        $attr = request()->validate([
            'query' => 'required',
            'type' => 'required|numeric'
        ]);

        if ($attr['type'] == 1) {
            $tickets = TicketModel::where('id', 'like', '%' . $attr['query'] . '%')->where('workspace', '=', $ws->id)->get();
        } else if ($attr['type'] == 2) {
            $tickets = TicketModel::where('hash', 'like', '%' . $attr['query'] . '%')->where('workspace', '=', $ws->id)->get();
        } else if ($attr['type'] == 3) {
            $tickets = TicketModel::where('subject', 'like', '%' . $attr['query'] . '%')->where('workspace', '=', $ws->id)->get();
        } else if ($attr['type'] == 4) {
            $tickets = TicketModel::where('text', 'like', '%' . $attr['query'] . '%')->where('workspace', '=', $ws->id)->get();
        } else {
            return back()->with('error', __('app.search_invalid_type'));
        }

        $groups = array();
        foreach ($tickets as $ticket)
        {
            $item = array();
            $item['ticket_id'] = $ticket->id;
            $group = GroupsModel::get($ticket->group);
            $item['group_name'] = ($group !== null) ? $group->name : '';
            array_push($groups, $item);
        }

        return view('ticket.searchresult', [
            'workspace' => $ws->name,
            'location' => __('app.ticket_search'),
            'user' => User::get(auth()->id()),
            'self' => User::getAgent(auth()->id()),
            'superadmin' => User::getAgent(auth()->id())->superadmin,
            'tickets' => $tickets,
            'groups' => $groups,
        ]);
    }

    /**
     * Save ticket notes
     * 
     * @param string $workspace
     * @param int $id The ticket ID
     * @return Illuminate\Http\RedirectResponse
     */
    public function saveNotes($workspace, $id)
    {
        if (!WorkSpaceModel::isLoggedIn($workspace)) {
            return back()->with('error', __('app.login_required'));
        }

        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        $attr = request()->validate([
            'notes' => 'required|max:4096'
        ]);

        $ticket = TicketModel::where('id', '=', $id)->where('workspace', '=', $ws->id)->first();
        if ($ticket) {
            $ticket->notes = $attr['notes'];
            $ticket->save();

            return back()->with('success', __('app.ticket_notes_saved'));
        }

        return back()->with('error', __('app.ticket_note_save_fail'));
    }

    /**
     * Let user download attachment
     * 
     * @param string $workspace
     * @param string $ticketId The ticket hash
     * @param int $id The attachment ID
     * @return Illuminate\Http\RedirectResponse
     */
    public function getAttachment($workspace, $ticketHash, $id)
    {
        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        $ticket = TicketModel::where('hash', '=', $ticketHash)->where('workspace', '=', $ws->id)->first();
        if (!$ticket) {
            return back()->with('error', __('app.ticket_not_found'));
        }

        $file = TicketsHaveFiles::where('id', '=', $id)->where('ticket_hash', '=', $ticketHash)->first();
        if (!$file) {
            return back()->with('error', __('app.ticket_file_not_found'));
        }

        return response()->download(base_path() . '/public/uploads/' . $file->file);
    }

    /**
     * Delete attachment
     * 
     * @param string $workspace
     * @param string $ticketId The ticket hash
     * @param int $id The attachment ID
     * @return Illuminate\Http\RedirectResponse
     */
    public function deleteAttachment($workspace, $ticketHash, $id)
    {
        $ws = WorkSpaceModel::where('name', '=', $workspace)->first();
        if ($ws === null) {
            return back()->with('error', __('app.workspace_not_found'));
        }

        $ticket = TicketModel::where('hash', '=', $ticketHash)->where('workspace', '=', $ws->id)->first();
        if (!$ticket) {
            return back()->with('error', __('app.ticket_not_found'));
        }

        $file = TicketsHaveFiles::where('id', '=', $id)->where('ticket_hash', '=', $ticketHash)->first();
        if (!$file) {
            return back()->with('error', __('app.ticket_file_not_found'));
        }

        unlink(base_path() . '/public/uploads/' . $file->file);

        $file->delete();

        return back()->with('success', __('app.ticket_file_deleted'));
    }
}
