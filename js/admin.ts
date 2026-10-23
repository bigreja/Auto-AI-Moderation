import app from 'flarum/admin/app';
import ModerationSettingsPage from './src/admin/components/ModerationSettingsPage';

app.initializers.add('tapao-moderationai', () => {
    app.beforeMount(() => {
        app.registry
            .for('tapao-auto-ai-moderation')
            .registerPage(ModerationSettingsPage);
    });
});
