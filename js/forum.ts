import app from 'flarum/forum/app';
import { override } from 'flarum/common/extend';
import Post from 'flarum/forum/components/Post';

app.initializers.add('tapao-moderationai', () => {
    if (typeof Post.prototype.flagReason !== 'function') return;

    override(Post.prototype, 'flagReason', function (original, flag) {
        if (flag.type() === 'ai_moderation') {
            return app.translator.trans('tapao-moderationai.forum.ai_flagged');
        }

        return original(flag);
    });
}, -100);