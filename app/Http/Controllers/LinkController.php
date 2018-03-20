<?php

namespace App\Http\Controllers;

use Session;
use App\Models\Link;
use App\Models\ParticipantVote;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Http\Request;
use App\Http\Requests;
use App\Repositories\Link\LinkRepositoryInterface;
use App\Repositories\Poll\PollRepositoryInterface;
use App\Repositories\Vote\VoteRepositoryInterface;
use App\Repositories\ParticipantVote\ParticipantVoteRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class LinkController extends Controller
{
    protected $linkRepository;
    protected $pollRepository;
    protected $voteRepository;
    protected $participantVoteRepository;

    public function __construct(
        LinkRepositoryInterface $linkRepository,
        PollRepositoryInterface $pollRepository,
        VoteRepositoryInterface $voteRepository,
        ParticipantVoteRepositoryInterface $participantVoteRepository
    ) {
        $this->linkRepository = $linkRepository;
        $this->pollRepository = $pollRepository;
        $this->voteRepository = $voteRepository;
        $this->participantVoteRepository = $participantVoteRepository;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($userId, $tokenRegister)
    {
        $user = User::where('token_verification', $tokenRegister)->first();

        if (! $user) {
            return view('errors.show_errors')->with('message', trans('polls.link_not_found'));
        }

        if ($userId == $user->id) {
            $user->is_active = true;
            $user->token_verification = '';
            $user->save();

            if (! Auth::login($user)) {
                return redirect()->to(url('/'))->withMessage(trans('user.register_account_successfully'));
            } else {
                return redirect()->to(url('/'))->withMessage(trans('user.register_account_fail'));
            }
        }

        return view('errors.404');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $token = $request->token;
        $links = Link::where('token', $token)->get();

        if ($links->count()) {
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($token, Request $request)
    {
        $link = $this->linkRepository->getPollByToken($token);

        if (! $link) {
            return view('errors.404');
        }

        $linkUser = url('link') . '/' . $link->token;
        $numberOfVote = config('settings.default_value');
        $voteLimit = null;
        $isRequiredEmail = false;
        $isRequiredName = false;
        $isRequiredNameAndEmail = false;
        $isLimit = false;
        $isHideResult = false;
        $isTimeOut = false;
        $isAllowAddOption = false;
        $isNoTheSameEmail = false;
        $isAccecptTypeMail = false;
        $isEditVoted = false;
        $isRequiredAuthWsm = false;
        $isDisableVoting = false;
        $poll = $link->poll;
        $totalVote = config('settings.default_value');
        $messageImage = trans('polls.message_client');
        $numberOfVote = false;

        $poll->load('options.users', 'options.participants', 'options.votes');

        $listVoter = $poll->options->reduce(function ($lookup, $item) {
            $lookup[$item->id] = $item->listVoter();

            return $lookup;
        });

        // Show result options
        $optionDates = $this->pollRepository->showOptionDate($poll);

        //count number of vote
        $countParticipantsVoted = $optionDates['participants']->count();

        $totalVote = [];
        // check option have image?
        $isHaveImages = false;

        foreach ($poll->options as $option) {
            if ($option->image) {
                $isHaveImages = true;
                break;
            }
        }

        foreach ($poll->options as $option) {
            $totalVote[$option->id] = $option->countVotes();

            if ($option->image) {
                $isHaveImages = true;
            }
        }

        $optionRateBarChart = [];

        // if (array_sum($totalVote)) {
            foreach ($poll->options as $option) {
                $countOption = $totalVote[$option->id];

                // if ($countOption > 0) {
                    if ($isHaveImages) {
                        $optionRateBarChart[] = ['<img src="' . $option->showImage() .'" class="image-option-poll">' . '<span class="name-option-poll">' . $option->name . '</span>', $countOption];
                    } else {
                        $optionRateBarChart[] = ['<p>' . $option->name . '</p>', $countOption];
                    }

                // }
            }
        // } else {
        //     $optionRateBarChart = null;
        // }

        $nameOptions = json_encode($this->pollRepository->getNameOptionToDrawChart($poll, $isHaveImages));
        $dataToDrawPieChart = json_encode($this->pollRepository->getDataToDrawPieChart($poll, $isHaveImages));
        $fontSize = $this->pollRepository->getSizeChart($poll)['fontSize'];

        $optionRateBarChart = json_encode($optionRateBarChart);
        $dataTableResult = $this->pollRepository->getDataTableResult($poll);

        //sort option and count vote by number of vote
        $dataTableResult = array_values(array_reverse(array_sort($dataTableResult, function($value)
        {
            return $value['numberOfVote'];
        })));

        if (! $link->link_admin) {
            if ($link->poll->isClosed()) {
                return view('errors.show_errors')->with('message', trans('polls.message_poll_closed'))->with('pollId', $poll->id);
            }

            //check time close vote when time out
            if (Carbon::now()->toAtomString() > Carbon::parse($poll->date_close)->toAtomString()) {
                $isTimeOut = true;
            }

            $requiredPassword = null;
            $typeEmail = null;

            //get all settings of poll
            $listSettings = [];
            if ($poll->settings) {
                foreach ($poll->settings as $setting) {
                    $listSettings[] = $setting->key;

                    if ($setting->key == config('settings.setting.set_limit')) {
                        $voteLimit = $setting->value;
                    }

                    if ($setting->key == config('settings.setting.set_password')) {
                        $requiredPassword = $setting->value;
                    }

                    if ($setting->key == config('settings.setting.add_type_mail')) {
                        $typeEmail = $setting->value;
                    }
                }

                if (collect($listSettings)->contains(config('settings.setting.required_name'))) {
                    $isRequiredName = true;
                }

                if (collect($listSettings)->contains(config('settings.setting.required_name_and_email'))) {
                    $isRequiredNameAndEmail = true;
                }

                if (collect($listSettings)->contains(config('settings.setting.required_email'))) {
                    $isRequiredEmail = true;
                }

                if (collect($listSettings)->contains(config('settings.setting.required_auth_wsm'))) {

                    $isRequiredAuthWsm = true;
                    $logined = auth()->check();

                    if ($isRequiredAuthWsm
                        && (!$logined ||
                            ($logined && !auth()->user()->haveWsmAction())
                        )) {

                        if (!Session::has('tokenSettingRequireAuthWsm')) {
                            Session::put('tokenSettingRequireAuthWsm', $link->token);
                        }

                        return view('errors.wsm_errors')
                            ->with('message', trans('polls.message_client.required_auth_wsm'))
                            ->with('token', $link->token);
                    }
                }

                if (collect($listSettings)->contains(config('settings.setting.hide_result'))) {
                    $isHideResult = true;
                }

                if (collect($listSettings)->contains(config('settings.setting.allow_add_option'))) {
                    $isAllowAddOption = true;
                }

                if (collect($listSettings)->contains(config('settings.setting.not_same_email'))) {
                    $isNoTheSameEmail = true;
                }

                if (collect($listSettings)->contains(config('settings.setting.allow_edit_vote_of_poll'))) {
                    $isEditVoted = true;
                }

                if (collect($listSettings)->contains(config('settings.setting.add_type_mail'))) {
                    $isAccecptTypeMail = true;
                }

                if (collect($listSettings)->contains(config('settings.setting.disable_voting'))) {
                    $isDisableVoting = true;
                }

                if (collect($listSettings)->contains(config('settings.setting.number_of_vote'))) {
                    $numberOfVote = true;
                }

                if ($voteLimit && $countParticipantsVoted >= $voteLimit) {
                    $isLimit = true;
                }
            }

            if(! Session::has('isInputPassword')) {
                if ($requiredPassword) {

                    return view('user.poll.input_password', compact('poll', 'requiredPassword', 'token'));
                }
            } elseif (! Session::get('isInputPassword')) {
                Session::forget('isInputPassword');

                return view('user.poll.input_password', compact('poll', 'requiredPassword', 'token'))->withErrors(trans('polls.incorrect_password'));
            }

            Session::forget('isInputPassword');

            $isOwnerPoll = \Gate::allows('ownerPoll', $poll);

            $viewOption = $this->pollRepository->getDataPollSystem()['jsonData'];

            return view('user.poll.details', compact(
                'poll', 'numberOfVote', 'linkUser', //poll info
                'isRequiredEmail', 'isRequiredName', 'isRequiredNameAndEmail', //setting required
                'isHideResult', //setting hide result
                'isLimit', //setting number limit of poll
                'isSetIp', //setting vote one time
                'requiredPassword', //setting password of poll
                'isParticipantVoted', // vote type
                'isTimeOut', //time out of poll
                'isAllowAddOption',// allow to voter add new option
                'isNoTheSameEmail',// setting not same email when setting had required email
                'isEditVoted', // Allow edit vote of poll
                'isRequiredAuthWsm', // Setting which only login by WSM system in framgia to vote (internal in framgia)
                'isDisableVoting', // Disable voting (Only see options of poll but don\'t vote those)
                'optionRateBarChart', 'dataTableResult', //result
                'countParticipantsVoted', 'isHaveImages', 'nameOptions', 'dataToDrawPieChart',
                'isOwnerPoll', 'fontSize', 'messageImage',
                'viewOption',
                'optionDates',
                'isAccecptTypeMail', 'typeEmail', // Setting for only accecpt that mail
                'listVoter',
                'numberOfVote'
            ));
        } else {
            foreach ($poll->links as $link) {
                if ($link->link_admin) {
                    $tokenLinkAdmin = $link->token;
                } else {
                    $tokenLinkUser = $link->token;
                }
            }

            //get data contain config or message return view and js
            $data = $this->pollRepository->getDataPollSystem();
            $page = 'manager';
            $settings = $this->pollRepository->showSetting($poll->settings);

            return view('user.poll.manage_poll', compact(
                'poll', 'tokenLinkUser', 'tokenLinkAdmin', 'numberOfVote',
                'linkUser', 'optionDates', 'isHaveImages',
                'settings', 'data', 'page', 'statistic', 'dataTableResult',
                'optionRateBarChart', 'optionRatePieChart', 'countParticipantsVoted',
                'isHaveImages', 'nameOptions', 'dataToDrawPieChart', 'fontSize'
            ));
        }
    }
}
