<?php

class Lesson extends MagniloquentContextsPlus {

    const ROLE_STUDENT = 'student';
    const ROLE_TUTOR = 'tutor';
    const ROLE_STUDENT_AND_TUTOR = 'mixed';

    const EVENT_COLOR_STUDENT = '#FED2A1';
    const EVENT_COLOR_TUTOR = '#CF6F07';
    const EVENT_COLOR_MIXED = '#F38918';

    const REPEAT_NONE   = 0;
    const REPEAT_DAILY  = 1;
    const REPEAT_WEEKLY = 2;

    const HISTORY_TYPE_CREATED      = 'created';
    const HISTORY_TYPE_CHANGE       = 'change';
    const HISTORY_TYPE_CONFIRMATION = 'confirmation';
    const HISTORY_TYPE_CANCELLATION = 'cancellation';

    /**
     * Adjusting our Laravel created / updated column names to match
     * what we had in Botangle
     *
     * @TODO: take this out long-term to keep things more consistent between this and normal Laravel apps
     * Note: this table doesn't have a CREATED_AT, so a mutator is in place lower down to prevent that
     * causing problems.
     */
    const UPDATED_AT = 'add_date';

    /**
     * What POST values we'll even take with massive assignment
     * @var array
     */
    protected $fillable = array(
        'tutor',
        'student',
        'lesson_date',
        'lesson_time',
        'duration',
        'subject',
        'repet',
        'notes',
        'active',
        'is_confirmed',
    );

    protected $guarded = array('history');

    /**
     * Former and Form classes will use these niceNames instead of deriving them from the db column names
     * where not specific label is defined on a form, etc.
     * @var array
     */
    protected $niceNames = array(
    );

    /**
     * Validation rules
     */
    public static $rules = array(
        "save" => array(
            'tutor'                     => array('required', 'exists:users,id'),
            'student'                   => array('required', 'exists:users,id'),
            'lesson_date'               => array('required', 'date_format:Y-m-d'),
            'lesson_time'               => array('required', 'date_format:G:i:s'),
            'duration'                  => array('numeric'),
            'subject'                   => array('required'),
            'repet'                     => array('in:0,1,2'), // Same as CONSTs beginning REPEAT_
        ),
        // additional validation rules for the following contexts
        'update'    => array(),
        'create'    => array(),
    );

    private $_openTokToken;

    /**
     * Lesson doesn't have an created_at column but is does have a updated_at column (add_date) - see CONST above
     * This mutator prevents Eloquent from trying to include the created_at column
     * in db updates.
     * @param $value
     */
    public function setCreatedAtAttribute($value)
    {
        // Do nothing.
    }

    public function studentUser()
    {
        return $this->belongsTo('User', 'student');
    }

    public function tutorUser()
    {
        return $this->belongsTo('User', 'tutor');
    }

    public function payments()
    {
        return $this->hasMany('LessonPayment');
    }

    public function review()
    {
        return $this->hasOne('Review');
    }

    /**
     * Generally we're only interested in active lessons
     * @param $query
     */
    public function scopeActive($query)
    {
        $query->where('active', true);
    }

    /**
     * @param $query
     * @param User $user
     */
    public function scopeInvolvingUser($query, User $user)
    {
        $query->where(function($query) use($user){
            $query->where('tutor', $user->id)
                ->orWhere('student', $user->id);
        });
    }

    /**
     * Lessons where the user is the one who needs to confirm changes
     * @param $query
     * @param User $user
     */
    public function scopeUnread($query, User $user)
    {
        // this whole thing needs to be wrapped in the outer where, otherwise there might be unexpected results
        //  when combined with other queries and scopes
        $query->where(function($query) use($user){
                // Is student for lesson where student hasn't read
                $query->where(function($query) use($user){
                        $query->where('student', $user->id)
                            ->where('readlesson', false);
                    });
                // or is tutor lesson where tutor hasn't read
                $query->orWhere(function($query) use($user){
                        $query->where('tutor', $user->id)
                            ->where('readlessontutor', false);
                    });
            });
    }

    /**
     * Lessons that are still proposals (active, unconfirmed and in the future)
     * @param $query
     */
    public function scopeProposals($query)
    {
        $query->where('is_confirmed', false)
            ->where('lesson_date', '>=', date('Y-m-d'));
    }

    /**
     * Lessons that are upcoming (active, confirmed and in the future)
     * @param $query
     */
    public function scopeUpcoming($query)
    {
        $query->where('is_confirmed', true)
            ->where('lesson_date', '>=', date('Y-m-d'));
    }

    /**
     * Lessons that are past (active, confirmed and in the past)
     * @param $query
     */
    public function scopePast($query)
    {
        $query->where('is_confirmed', true)
            ->where('lesson_date', '<', date('Y-m-d'));
    }

    /**
     * Returns the calendar event title corresponding to the current lesson
     * If the current logged in user is either the student or tutor, the title provides details.
     *
     * @param $loggedInUserId
     * @param $viewingUserId
     * @return string
     */
    public function getCalendarEventTitle($loggedInUserId, $viewingUserId)
    {
        // Since subject and username are user entered, HTML encode them since they'll be directly output
        //   to a 3rd party js plugin that may not encode the text
        $lessonTime = DateTime::createFromFormat('H:i:s', $this->lesson_time)->format('g:i A');

        if ($this->studentUser->id == $loggedInUserId){
            $title = e($this->subject);
            if ($loggedInUserId == $viewingUserId){
                $title .= "\n   ". Lang::get('Tutor: '). e($this->tutorUser->username);
            } else {
                $title .= "\n   ". Lang::get('Student: '). e($this->studentUser->username);
            }
            $title .= "\n   ". trans('When: '). $lessonTime;

        } elseif ($this->tutorUser->id == $loggedInUserId){
            $title = e($this->subject);
            if ($loggedInUserId == $viewingUserId){
                $title .= "\n   ". Lang::get('Student: '). e($this->studentUser->username);
            } else {
                $title .= "\n   ". Lang::get('Tutor: '). e($this->tutorUser->username);
            }
            $title .= "\n   ". trans('When: '). $lessonTime;

        } else {
            $title = "Lesson at {$lessonTime}";
        }
        if (!$this->is_confirmed){
            $title .= ' ('. trans ('unconfirmed') . ')';
        }
        return $title;
    }

    /**
     * Works out the day type (used to determine the appropriate color to display on calendar)
     * given the current lesson and the previous lessons on the same day
     * @param $viewingUserId
     * @param $loggedInUserId
     * @param null $currentDayType
     * @return null|string
     */
    public function getDayType($viewingUserId, $loggedInUserId, $currentDayType = null)
    {
        if ($currentDayType == null){
            return $this->getLessonDayType($viewingUserId, $loggedInUserId);
        } else {
            $latestDayType = $this->getLessonDayType($viewingUserId, $loggedInUserId);
            if ($currentDayType != $latestDayType){
                return Lesson::ROLE_STUDENT_AND_TUTOR;
            } else {
                return $currentDayType;
            }
        }
    }

    /**
     * Gets the calendar day type corresponding to the current lesson for the user profile
     * @param $viewingUserId
     * @param $loggedInUserId
     * @return string
     */
    public function getLessonDayType($viewingUserId, $loggedInUserId)
    {
        if ($this->tutorUser->id != $loggedInUserId && $this->studentUser->id != $loggedInUserId){
            return Lesson::ROLE_STUDENT_AND_TUTOR;
        }

        if ($this->tutorUser->id == $viewingUserId) {
            return Lesson::ROLE_TUTOR;
        } elseif ($this->studentUser->id == $viewingUserId){
            return Lesson::ROLE_STUDENT;
        } else {
            return Lesson::ROLE_STUDENT_AND_TUTOR;
        }
    }

    /**
     * Returns the color to be used for the day background on the calendar based on the $dayType
     * @param $dayType
     * @return string
     */
    public static function getCalendarEventColor($dayType)
    {
        switch($dayType){
            case self::ROLE_TUTOR:
                return self::EVENT_COLOR_TUTOR;
            case self::ROLE_STUDENT:
                return self::EVENT_COLOR_STUDENT;
            case self::ROLE_STUDENT_AND_TUTOR:
                return self::EVENT_COLOR_MIXED;
        }
    }

    public static function getDurationOptions()
    {
        $options = array();
        for($i = 1; $i < 25; $i++){
            if ($i == 1){
                $text = '30 ' . trans('minutes');
            } elseif ($i == 2){
                $text = '1 ' . trans('hour');
            } else {
                $text = ($i/2) .' ' . trans('hours');
            }
            $options[$i*30] = $text;
        }
        return $options;
    }

    public static function getRepetitionOptions()
    {
        return array(
            self::REPEAT_NONE       => trans('Single lesson'),
            self::REPEAT_DAILY      => trans('Daily'),
            self::REPEAT_WEEKLY     => trans('Weekly'),
        );
        /**
         * According to https://github.com/Anahkiasen/former/wiki/Features#checkboxes-and-radios
         * this ought to work. It doesn't though - perhaps it will in a later version.
         *
        return array(
            'label' => array(
                'name'      => trans('Single lesson'),
                'value'     => self::REPEAT_NONE,
            ),
            'label' => array(
                'name'      => trans('Daily'),
                'value'     => self::REPEAT_DAILY,
            ),
            'label' => array(
                'name'      => trans('Weekly'),
                'value'     => self::REPEAT_WEEKLY,
            ),
        );
        */
    }

    /**
     * Adds history $eventData of $type to the history attribute.
     * Note: this data is held on the Lesson model and
     * the Lesson model MUST BE SAVED to save your history changes.
     *
     * @param $type Should be one of the HISTORY_TYPE constants
     * @param $eventData Could be a string or an array
     */
    public function addHistory($type, $eventData)
    {
        $history = json_decode($this->history);
        if (empty($history)){
            $history = array();
        }
        $history[] = array(
            'type'      => $type,
            'user_id'   => Auth::user()->id,
            'username'  => Auth::user()->username, // for readability of the raw history
            'when'      => (new \DateTime())->format('Y-m-d H:i:s'), // TODO: consider timezone issues
            'data'      => $eventData,
        );
        $this->history = json_encode($history);
    }

    /**
     * Update the lesson status attributes according to the changes made since the last version of the lesson
     * @param bool $isNew
     * @param bool $isConfirming
     * @return bool
     */
    public function manageChangesThenSave($isNew = false, $isConfirming = false)
    {
        if ($isNew){
            $this->addHistory(self::HISTORY_TYPE_CREATED, null);
            $this->updateStatusesAfterChanges();
            return $this->save();

        } elseif ($isConfirming) {
            $this->confirm(Auth::user());
            $this->addHistory(self::HISTORY_TYPE_CONFIRMATION, null);
            return $this->save();

        } else {
            $changesArray = array();
            foreach ($this->getDirty() as $field => $newData)
            {
                $oldData = $this->getOriginal($field);
                if ($oldData != $newData)
                {
                    $changesArray[$field] = array(
                        'from'  => $oldData,
                        'to'    => $newData,
                    );
                }
            }
            if (count($changesArray) > 0){
                $this->addHistory(self::HISTORY_TYPE_CHANGE, $changesArray);
                $this->updateStatusesAfterChanges();
                return $this->save();
            }
        }
        return true;
    }

    /**
     * Updates the lesson statuses to reflect that the current logged in user has made changes to the lesson
     * that need to be reviewed and confirmed by the other user related to the lesson.
     * Note: this now handles the circumstance where a 3rd party could amend the lesson requiring both the tutor
     * and the student to confirm the changes. Not sure it'll ever be needed but it was simple to add.
     */
    public function updateStatusesAfterChanges()
    {
        $loggedInUserId = Auth::user()->id;
        if ($loggedInUserId == $this->student) {
            // the tutor hasn't confirmed this change yet, but the student has
            $this->readlessontutor = 0;
            $this->readlesson = 1;

            // the student made the last change and the tutor didn't
            $this->laststatus_student = 1;
            $this->laststatus_tutor = 0;

        } else if ($loggedInUserId == $this->tutor) {
            // the student hasn't confirmed this change yet, but the tutor has
            $this->readlesson = 0;
            $this->readlessontutor = 1;

            // the tutor made the last change and the student didn't
            $this->laststatus_tutor = 1;
            $this->laststatus_student = 0;
        } else {
            $this->readlesson = 0;
            $this->readlessontutor = 0;
            $this->laststatus_tutor = 0;
            $this->laststatus_student = 0;
        }
        $this->is_confirmed = false;
    }

    /**
     * User $user confirms that they have accepted the lesson
     * @param User $user
     */
    public function confirm(User $user)
    {
        if ($user->id == $this->tutor){
            $this->readlessontutor = 1;
            $this->laststatus_tutor = 1;
            if ($this->readlesson){
                $this->is_confirmed = true;
            }
        } elseif ($user->id == $this->student){
            $this->readlesson = 1;
            $this->laststatus_student = 1;
            if ($this->readlessontutor){
                $this->is_confirmed = true;
            }
        }
    }

    public function formatLessonDate($format)
    {
        $date = DateTime::createFromFormat('Y-m-d', $this->lesson_date);
        if ($date){
            return $date->format($format);
        }
        return null;
    }

    /**
     * Returns the lesson_time attribute formatted according to $format
     * @param $format
     * @return null|string
     */
    public function formatLessonTime($format)
    {
        /**
         * A little hack because the validation of lesson_time must be G:i:s, so that a freshly retrieved
         * lesson from the db passes validation. However, data coming from the datetimepicker is just G:i.
         * Unfortunately, using a get mutator doesn't work, since it's not automatically called before the
         * validation is called. So, I'm using this little function to isolate my workaround and
         * convert between the two formats.
         * MJL - 2014-08-13
         */
        $length = strlen($this->lesson_time);
        if ($length == 5 || $length == 4){
            $date = DateTime::createFromFormat('G:i', $this->lesson_time);
        } elseif ($length == 8 || $length == 7){
            $date = DateTime::createFromFormat('G:i:s', $this->lesson_time);
        } else {
            return null;
        }

        if ($date){
            return $date->format($format);
        }
        return null;
    }

    public function getDurationAttribute($value)
    {
        return $value * 60;
    }

    public function setDurationAttribute($value)
    {
        $this->attributes['duration'] = $value / 60;
    }

    public function getDisplayDurationAttribute()
    {
        $duration = $this->duration / 60;
        if($duration == .5) {
            return '30 '. trans('minutes');
        } elseif($duration == 1.0) {
            return '1 ' . trans('hour');
        } else {
            return $duration .' ' . trans('hours');
        }
    }

    /**
     * Can the current user confirm this lesson
     * @param User $user
     * @return bool
     */
    public function userCanConfirm(User $user)
    {
        if (!$this->is_confirmed){
            if ($this->userIsTutor($user) && !$this->readlessontutor){
                return true;
            } elseif (($this->userIsStudent($user) && !$this->readlesson)){
                return true;
            }
        }
        return false;
    }

    /**
     * Is $user the lesson's tutor
     * @param User $user
     * @return bool
     */
    public function userIsTutor(User $user)
    {
        return ($user->id == $this->tutor);
    }

    /**
     * Is $user the lesson's student
     * @param User $user
     * @return bool
     */
    public function userIsStudent(User $user)
    {
        return ($user->id == $this->student);
    }

    public function determineMessageRecipient(User $authUser)
    {
        if ($this->userIsTutor($authUser)){
            return $this->studentUser;
        } elseif ($this->userIsStudent($authUser)){
            return $this->tutorUser;
        } else {
            return null;
        }
    }

    /**
     * Sends a message to the other user (i.e. the one who isn't the current user)
     *
     * @param $viewName The name of the view to generate the body of the message combined with $this Lesson
     * @param User $authUser The authenticated user
     * @return null|UserMessage
     */
    public function sendLessonMessage($viewName, User $authUser)
    {
        // TODO A better implementation would be to call UserMessage::send(Lesson $lesson,...)
        //  where Lesson implements an interface that allows UserMessage to get the data it requires
        //  instead of this function

        $recipient = $this->determineMessageRecipient($authUser);
        if (!$recipient){
            return null;
        }
        $message = new UserMessage;
        if (!$message->send($authUser, $recipient, $viewName, array(
                    'model' => $this,
                ))){
            // TODO Log $message->errors()

        }
    }

    /**
     * Note: $authUser is passed in instead of referencing Auth::user() since this might be a queued job initiated
     * by a CRON task.
     * @param $eventType
     * @param User $authUser The authenticated user
     * @param $description = ''
     * @return array|\Illuminate\Database\Eloquent\Model|static
     */
    public function logUserEvent($eventType, User $authUser, $description = '')
    {
        // TODO Improve by replacing this function with dependency injection of Lesson to UserLog::new()
        $logEntry = UserLog::create(array(
                'user_id'           => $authUser->id,
                'type'              => $eventType,
                'related_type_id'   => $this->id,
                'created'           => $this->freshTimestampString(),
                'description'       => $description,
            ));
        if (!$logEntry->id){
            Event::fire('user_log.errors', array($logEntry->errors()));
        }
    }

    /**
     * Prepare for the lesson by generating the OpenTok session and Twiddla meeting ids
     */
    public function prepareLessonTools()
    {
        // TODO: Consider queueing these for completion by a CRON task because it
        //  creates a long pause for the person who inadvertently triggered this
        if (!$this->opentok_session_id){
            $this->generateOpenTokSessionId();
        }
        if (!$this->twiddlameetingid){
            $this->generateTwiddlaMeetingId();
        }
    }

    /**
     * Generate the Twiddla meeting id for the lesson and store it in the db
     * @return Lesson
     */
    protected function generateTwiddlaMeetingId()
    {
        $twiddla = new Twiddla(Config::get('services.twiddla.username'), Config::get('services.twiddla.password'));
        $this->twiddlameetingid = $twiddla->getMeetingId();
        return $this->save();
    }

    /**
     * Generate the OpenTok session id for the lesson and store it in the db
     * @return Lesson
     */
    protected function generateOpenTokSessionId()
    {
        $openTok = new OpenTok(Config::get('services.openTok.apiKey'), Config::get('services.openTok.apiSecret'));
        $this->opentok_session_id = $openTok->generateSessionId();
        return $this->save();
    }

    /**
     * A stub for a billing check required before Twiddla and OpenTok sessions can be created
     */
    public function billingReady()
    {
        // @TODO Implement this when billing is implemented
        return true;
    }

    public function getOpenTokTokenAttribute()
    {
        if (!isset($this->_openTokToken)){
            $openTok = new OpenTok(Config::get('services.openTok.apiKey'), Config::get('services.openTok.apiSecret'));
            $this->_openTokToken = $openTok->generateToken($this->opentok_session_id);
        }
        return $this->_openTokToken;
    }
}
