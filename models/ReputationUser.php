<?php
/**
 * This is the model class for table "reputation_user".
 *
 * The followings are the available columns in table 'reputation_user':
 * @property integer $id
 * @property integer $value
 * @property integer $visibility
 * @property integer $user_id
 * @property integer $space_id
 * @property string $created_at
 * @property integer $created_by
 * @property string $updated_at
 * @property integer $updated_by
 *
 * @author Anton Kurnitzky (v0.11) & Philipp Horna (v0.20+) */

namespace humhub\modules\reputation\models;

use humhub\modules\comment\models\Comment;
use humhub\modules\content\models\Content;
use humhub\modules\like\models\Like;
use humhub\modules\space\models\Membership;
use Yii;
use yii\db\Exception;

class ReputationUser extends ReputationBase {
    /*
     * Key-Value pair
     * Key: YY-MM-DD
     * Value: The reputation score the user gained on this day
     */
    private static $daily_reputation = array();

    /**
     * @inheritdoc
     */
    public static function tableName() {
        return 'reputation_user';
    }

    /**
     * @return array validation rules for model attributes.
     */
    public function rules() {
        return array(
                [['value', 'visibility', 'user_id', 'space_id'], 'required'],
                [['value', 'visibility', 'user_id', 'space_id', 'created_by', 'updated_by'], 'integer',],
                [['created_at', 'updated_at'], 'safe']
        );
    }

    /**
     * @return array relational rules.

      public function relations() {
      return array(
      'user' => array(self::BELONGS_TO, 'User', 'user_id'),
      'space' => array(self::BELONGS_TO, 'Space', 'space_id'),
      );
      }
     */

    /**
     * @return array customized attribute labels (name=>label)
     */
    public function attributeLabels() {
        return array(
            'id' => 'ID',
            'value' => 'Value',
            'visibility' => 'Visibility',
            'user_id' => 'User',
            'space_id' => 'Space',        
            'created_at' => 'Created At',
            'created_by' => 'Created By',
            'updated_at' => 'Updated At',
            'updated_by' => 'Updated By',
        );
    }

    /**
     * Updates all user reputation for this space
     * @param $space : The space to check
     * @param bool $forceUpdate : Ignore cache
     */
    public function updateUserReputation($space, $forceUpdate = false) {
        $spaceId = $space->id;

        // get all users from this space
        $attributes = array('space_id' => $spaceId);
        $spaceUsers = Membership::findAll($attributes);

        foreach ($spaceUsers as $user) {

            $cacheId = 'reputation_space_user' . '_' . $spaceId . '_' . $user->user_id;
            $userReputation = Yii::$app->cache->get($cacheId);

            if ($userReputation === false || $forceUpdate === true) {

                // get all reputation_user objects from this space
                $condition = array('user_id' => $user->user_id, 'space_id' => $spaceId);
                $userReputation = ReputationUser::findOne($condition);

                if ($userReputation == null && !Yii::$app->user->isGuest) {
                    // Create new reputation_user entry
                    $userReputation = new ReputationUser;
                    $userReputation->user_id = $user->user_id;
                    $userReputation->space_id = $spaceId;
                    $userReputation->visibility = 0;
                    $userReputation->created_by = $user->user_id;
                }
                $userReputation->value = ReputationUser::calculateUserReputationScore($user->user_id, $space, $forceUpdate);
                $userReputation->updated_at = date('Y-m-d H:i:s');
                $userReputation->save();

                Yii::$app->cache->set($cacheId, $userReputation, ReputationBase::CACHE_TIME_SECONDS);
            }
        }

        ReputationUser::deleteMissingUsers($spaceId);
    }

    /**
     * Calculate the user reputation score inside a space
     * Use likes, favorites, comments from content that user created
     * Use posted comments and likes received for this comments for content the user didn't create
     * Include limitations in calculate like weight decrease and daily limit
     *
     * @param $userId : The userId to calculate reputation for
     * @param $space : The space the calculation is being based on
     * @return int: User reputation score inside this space
     */
    private function calculateUserReputationScore($userId, $space, $forceUpdate = false) {
        $spaceSettings = ReputationBase::getSpaceSettings(); // array containg all space settings
        $dailyLimit = $spaceSettings[6]; // max points a user can receive on one single day
        $decreaseWeighting = $spaceSettings[7];  // should weighting of repeating actions be decreased

        $spaceContent = ReputationBase::getContentFromSpace($space->id, $forceUpdate);

        foreach ($spaceContent as $content) {
            /*
             * keep track of how many times an content object was liked, favorited etc.
             * this allows to decrease the value of repeating actions
             * e.g. the second like only gives the user half the points from the first like
             */
            $scoreCount = 1;

            /*
             * handle content that is created by this user
             * use likes, favorites, comments from content that user created
             */
            if ($content->created_by == $userId) {
                ReputationUser::addToDailyReputation($content, $spaceSettings[2], $dailyLimit);

                // now count the likes this content received from other users
                $cacheId = 'likes_earned_cache_' . $userId . '_' . $content->id;
                $likes = ReputationBase::getLikesFromContent($content, $userId, $cacheId, $forceUpdate);
                foreach ($likes as $like) {
                    if ($decreaseWeighting == '1') {
                        ReputationUser::addToDailyReputation($like, $spaceSettings[3] / $scoreCount, $dailyLimit);
                    } else {
                        ReputationUser::addToDailyReputation($like, $spaceSettings[3], $dailyLimit);
                    }

                    $scoreCount++;
                }

                if ($space->isModuleEnabled('favorite')) {
                    $scoreCount = 1;
                    // now count the favorites this content received from other users
                    $cacheId = 'favorites_earned_cache_' . $userId . '_' . $content->id;
                    $favorites = ReputationBase::getFavoritesFromContent($content, $userId, $cacheId, $forceUpdate);
                    foreach ($favorites as $favorite) {
                        if ($decreaseWeighting == '1') {
                            ReputationUser::addToDailyReputation($favorite, $spaceSettings[4] / $scoreCount, $dailyLimit);
                        } else {
                            ReputationUser::addToDailyReputation($favorite, $spaceSettings[4], $dailyLimit);
                        }

                        $scoreCount++;
                    }
                }

                $scoreCount = 1;

                // now count how many comments this post has generated
                $cacheId = 'comments_earned_cache_' . $userId . '_' . $content->id;
                $comments = ReputationBase::getCommentsFromContent($content, $userId, $cacheId, false, $forceUpdate);
                foreach ($comments as $comment) {
                    if ($decreaseWeighting == '1') {
                        ReputationUser::addToDailyReputation($comment, $spaceSettings[5] / $scoreCount, $dailyLimit);
                    } else {
                        ReputationUser::addToDailyReputation($comment, $spaceSettings[5], $dailyLimit);
                    }

                    $scoreCount++;
                }

                $scoreCount = 1;
            }

            /**
             * now handle posts that were created by others users
             * The user gets points for comments he created and for likes the comments have received
             */
            $commentsPosted = ReputationUser::GetCommentsGeneratedByUser($userId, $content, $forceUpdate);
            foreach ($commentsPosted as $commentPosted) {
                ReputationUser::addToDailyReputation($commentPosted, $spaceSettings[2], $dailyLimit);
            }

            $commentsLiked = ReputationUser::GetCommentsGeneratedByUserLikedByOthers($userId, $content, $forceUpdate);
            foreach ($commentsLiked as $commentLiked) {
                if ($decreaseWeighting == '1') {
                    ReputationUser::addToDailyReputation($commentLiked, $spaceSettings[3] / $scoreCount, $dailyLimit);
                } else {
                    ReputationUser::addToDailyReputation($commentLiked, $spaceSettings[3], $dailyLimit);
                }
                $scoreCount++;
            }
        }

        /*
         * Iterate over daily_reputation structure to get final score
         */
        $reputationScore = 0;
        foreach (ReputationUser::$daily_reputation as $reputation) {
            $reputationScore += $reputation->getScore();
        }

        // reset this array for next user
        ReputationUser::$daily_reputation = array();



        return ReputationUser::calculateUserScore($spaceSettings[0], $reputationScore, $spaceSettings[1]);
    }

    /**
     * @param $content
     * @param $scoreToAdd
     * @param $daily_limit
     * @return array
     */
    private function addToDailyReputation($content, $scoreToAdd, $daily_limit) {
        global $daily_reputation;
        $date = date_create($content->created_at)->format('Y-m-d');

        if (array_key_exists($date, ReputationUser::$daily_reputation)) {
            $currentDate = ReputationUser::$daily_reputation[$date];
            $currentDate->addScore($scoreToAdd);

            return array($date, $currentDate, ReputationUser::$daily_reputation);
        } else {
            ReputationUser::$daily_reputation[$date] = new DailyReputation($scoreToAdd, $daily_limit);
        }
    }

    /**
     * Get all comments the user created.
     *
     * @param $userId
     * @param Content $content
     * @param $forceUpdate : Ignore cache
     * @return Comment[]
     */
    public function GetCommentsGeneratedByUser($userId, Content $content, $forceUpdate = false) {
        $cacheId = 'comments_generated_cache_' . $userId . '_' . $content->id;

        $commentsGenerated = Yii::$app->cache->get($cacheId);

        if ($commentsGenerated === false || $forceUpdate === true) {
            $object = $content->object_model;
            $objectModel = $object::tableName();
            $commentsGenerated = array();
            try {
                $query = Comment::find();
                $query->leftJoin($objectModel . ' AS o', 'comment.object_id = o.id');
                $query->leftJoin('content AS ct', 'o.id = ct.object_id');
                $params = array(':contentId' => $content->id, ':userId' => $userId);
                $query->where('ct.id=:contentId AND c.created_by=:userId AND c.object_model=ct.object_model', $params);
                $commentsGenerated = $query->all();

                Yii::$app->cache->set($cacheId, $commentsGenerated, ReputationBase::CACHE_TIME_SECONDS);
            } catch (Exception $e) {
                Yii::trace('Couldn\'t count generated comments from object model: ' . $objectModel);
            }
        }

        return $commentsGenerated;
    }

    /**
     * Get all likes that a user got for a comment he made
     * When a user likes his own comment it will not be counted
     *
     * @param $userId
     * @param Content $content
     * @param $forceUpdate : Ignore cache
     * @return int
     */
    public function GetCommentsGeneratedByUserLikedByOthers($userId, Content $content, $forceUpdate) {
        $cacheId = 'comments_liked_cache_' . $userId . '_' . $content->id;
        $commentsLiked = Yii::$app->cache->get($cacheId);
        if ($commentsLiked === false || $forceUpdate === true) {
            $object = $content->object_model;
            $objectModel = $object::tableName();
            $commentsLiked = array();
            try {
                $query = Like::find();
                $query->leftJoin('comment AS c', 'c.id = like.object_id');
                $query->leftJoin($objectModel . ' AS o', 'c.object_id = o.id');
                $query->leftJoin('content AS ct', 'o.id = ct.object_id');
                $params = array(':contentId' => $content->id, ':userId' => $userId);
                $query->where('like.object_model=\'humhub\modules\comment\models\Comment\' AND like.created_by!=:userId AND ct.id=:contentId AND c.created_by=:userId AND c.object_model=ct.object_model', $params);
                $commentsLiked = $query->all();

                Yii::$app->cache->set($cacheId, $commentsLiked, ReputationBase::CACHE_TIME_SECONDS);
            } catch (Exception $e) {
                Yii::trace('Couldn\'t count generated comments from object model: ' . $objectModel);
            }
        }
        return $commentsLiked;
    }

    /**
     * Calculate final user score.
     *
     * @param $function : Linear or Logarithmic
     * @param $reputationScore : The score the user reached
     * @param $logarithmBase : The logarithm base
     * @return int
     */
    private function calculateUserScore($function, $reputationScore, $logarithmBase) {
        if ($function == ReputationBase::LINEAR) {
            return intval($reputationScore);
        } else {
            if ($reputationScore == 0) {
                return 0;
            } else {
                // increase reputation score + 1 so log is not 0 when user has 1 point
                $logValue = log($reputationScore + 1, $logarithmBase);
                return intval(round($logValue * 100));
            }
        }
    }

    /**
     * Delete users that are not space members anymore
     *
     * @param $spaceId
     * @throws CDbException
     */
    private function deleteMissingUsers($spaceId) {
        $condition = array('space_id' => $spaceId);
        $reputationUsers = ReputationUser::findAll($condition);
        foreach ($reputationUsers as $user) {
            if (Membership::findOne('space_id=' . $spaceId . ' AND user_id=' . $user->user_id . '')) {
                Membership::delete();
            }
        }
    }

    /**
     * Get a array containing user e-mail addresses and their reputation score for a specific space
     * @param $spaceId
     * @return array()

      public function getSpaceUsersAndScore($spaceId) {
      $reputationUsers = Yii::$app->db->createCommand()
      ->select('u.email, ru.value')
      ->from('reputation_user ru')
      ->join('user u', 'ru.user_id=u.id')
      ->where('space_id=:spaceId', array(':spaceId' => $spaceId))
      ->queryAll();

      return $reputationUsers;
      }
     * 
     */
}
