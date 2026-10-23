import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Select from 'flarum/common/components/Select';
import CategoryThresholdRow from './CategoryThresholdRow';
import ModerationLogTable from './ModerationLogTable';

const CATEGORIES = [
    'sexual', 'sexual/minors', 'violence', 'violence/graphic',
    'hate', 'hate/threatening', 'harassment', 'harassment/threatening',
    'self-harm', 'self-harm/intent', 'illicit', 'illicit/violent',
];

export default class ModerationSettingsPage extends ExtensionPage {
    testStatus: string | null = null;

    oninit(vnode: any) {
        super.oninit(vnode);
        this.testStatus = null;
    }

    content() {
        return (
            <div className="ExtensionPage-settings">
                <div className="container">
            <div className="ModerationSettingsPage">
                <h2>{app.translator.trans('tapao-moderationai.admin.settings.title')}</h2>

                {/* Enable toggle */}
                <div className="Form-group">
                    <Switch
                        state={this.setting('moderationai.enabled')() === '1'}
                        onchange={(val: boolean) => this.setting('moderationai.enabled')(val ? '1' : '0')}
                    >
                        {app.translator.trans('tapao-moderationai.admin.settings.enabled_label')}
                    </Switch>
                </div>

                {/* API Key */}
                <div className="Form-group">
                    <label>{app.translator.trans('tapao-moderationai.admin.settings.api_key_label')}</label>
                    <input
                        type="password"
                        className="FormControl"
                        placeholder="sk-..."
                        bidi={this.setting('moderationai.openai_api_key')}
                    />
                </div>

                {/* Model */}
                <div className="Form-group">
                    <label>{app.translator.trans('tapao-moderationai.admin.settings.model_label')}</label>
                    <Select
                        value={this.setting('moderationai.model')() || 'omni-moderation-latest'}
                        onchange={this.setting('moderationai.model')}
                        options={{
                            'omni-moderation-latest': 'omni-moderation-latest (text + image)',
                            'text-moderation-latest': 'text-moderation-latest (text only, free)',
                        }}
                    />
                </div>

                {/* Mode */}
                <div className="Form-group">
                    <label>{app.translator.trans('tapao-moderationai.admin.settings.mode_label')}</label>
                    <Select
                        value={this.setting('moderationai.mode')() || 'sync'}
                        onchange={this.setting('moderationai.mode')}
                        options={{
                            sync:  app.translator.trans('tapao-moderationai.admin.settings.mode_sync'),
                            async: app.translator.trans('tapao-moderationai.admin.settings.mode_async'),
                        }}
                    />
                </div>

                {/* Scan images toggle */}
                <div className="Form-group">
                    <Switch
                        state={this.setting('moderationai.scan_images')() !== '0'}
                        onchange={(val: boolean) => this.setting('moderationai.scan_images')(val ? '1' : '0')}
                    >
                        {app.translator.trans('tapao-moderationai.admin.settings.scan_images_label')}
                    </Switch>
                </div>

                {/* Scan private messages toggle */}
                <div className="Form-group">
                    <Switch
                        state={this.setting('moderationai.scan_private_messages')() !== '0'}
                        onchange={(val: boolean) => this.setting('moderationai.scan_private_messages')(val ? '1' : '0')}
                    >
                        {app.translator.trans('tapao-moderationai.admin.settings.scan_private_messages_label')}
                    </Switch>
                </div>

                {/* Trust skip threshold */}
                <div className="Form-group">
                    <label>{app.translator.trans('tapao-moderationai.admin.settings.trust_skip_threshold_label')}</label>
                    <input
                        type="number"
                        className="FormControl"
                        min="0"
                        max="100"
                        bidi={this.setting('moderationai.trust_skip_threshold')}
                    />
                    <p className="helpText">{app.translator.trans('tapao-moderationai.admin.settings.trust_skip_threshold_help')}</p>
                </div>

                {/* Webhook URL */}
                <div className="Form-group">
                    <label>{app.translator.trans('tapao-moderationai.admin.settings.webhook_url_label')}</label>
                    <input
                        type="url"
                        className="FormControl"
                        placeholder="https://your-server.com/webhook"
                        bidi={this.setting('moderationai.webhook_url')}
                    />
                    <p className="helpText">{app.translator.trans('tapao-moderationai.admin.settings.webhook_url_help')}</p>
                </div>

                {/* Per-category thresholds */}
                <h3>{app.translator.trans('tapao-moderationai.admin.settings.thresholds_heading')}</h3>
                {CATEGORIES.map(category => (
                    <CategoryThresholdRow
                        key={category}
                        category={category}
                        threshold={this.setting(`moderationai.threshold.${category}`)}
                        action={this.setting(`moderationai.action.${category}`)}
                    />
                ))}

                {/* Test connection */}
                <div className="Form-group">
                    <Button
                        className="Button Button--primary"
                        onclick={() => this.testConnection()}
                    >
                        {app.translator.trans('tapao-moderationai.admin.settings.test_connection')}
                    </Button>
                    {this.testStatus && (
                        <span style={{ marginLeft: '1rem' }}>{this.testStatus}</span>
                    )}
                </div>

                {this.submitButton()}

                {/* Moderation log review queue */}
                <h3>{app.translator.trans('tapao-moderationai.admin.log.title')}</h3>
                <ModerationLogTable />
            </div>
                </div>
            </div>
        );
    }

    async testConnection() {
        this.testStatus = 'Testing…';
        m.redraw();
        try {
            const result: any = await app.request({
                method: 'POST',
                url: app.forum.attribute('apiUrl') + '/moderationai-test',
                body: {
                    apiKey: this.setting('moderationai.openai_api_key')(),
                    model: this.setting('moderationai.model')() || 'omni-moderation-latest',
                },
            });

            this.testStatus = result && result.ok
                ? app.translator.trans('tapao-moderationai.admin.settings.connection_ok') as string
                : (result?.error || app.translator.trans('tapao-moderationai.admin.settings.connection_fail') as string);
        } catch (e: any) {
            const message = e?.response?.error
                || e?.message
                || app.translator.trans('tapao-moderationai.admin.settings.connection_fail');
            this.testStatus = message as string;
        }
        m.redraw();
    }
}
