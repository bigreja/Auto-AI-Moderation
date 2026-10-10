import app from 'flarum/admin/app';
import ModerationSettingsPage from './src/admin/components/ModerationSettingsPage';

app.initializers.add('tapao-moderationai', () => {
    app.extensionData
        .for('tapao-auto-ai-moderation')
        .registerPage(ModerationSettingsPage);
});
