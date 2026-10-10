import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import ModerationLogItem from './ModerationLogItem';

export default class ModerationLogTable extends Component {
    logs: any[] = [];
    loading = true;
    error: string | null = null;

    oninit(vnode: any) {
        super.oninit(vnode);
        this.logs = [];
        this.loading = true;
        this.error = null;
        this.loadLogs();
    }

    async loadLogs() {
        this.loading = true;
        this.error = null;
        try {
            const response = await app.request<any>({
                method: 'GET',
                url: app.forum.attribute('apiUrl') + '/moderation-logs?flagged=1',
            });
            this.logs = response.data || [];
        } catch (e: any) {
            this.error = e?.response?.body?.errors?.[0]?.detail ?? 'Failed to load moderation log.';
            this.logs = [];
        } finally {
            this.loading = false;
            m.redraw();
        }
    }

    view() {
        if (this.loading) return <p>Loading moderation log…</p>;

        if (this.error) {
            return (
                <div className="Alert Alert--error">
                    <p>{this.error}</p>
                    <button className="Button Button--text" onclick={() => this.loadLogs()}>Retry</button>
                </div>
            );
        }

        if (this.logs.length === 0) {
            return <p>No flagged items pending review.</p>;
        }

        return (
            <div className="ModerationLogTable">
                <table className="Table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>ID</th>
                            <th>Field</th>
                            <th>Category</th>
                            <th>Score</th>
                            <th>AI Action</th>
                            <th>Review</th>
                        </tr>
                    </thead>
                    <tbody>
                        {this.logs.map((log: any) => (
                            <ModerationLogItem key={log.id} log={log} onReview={() => this.loadLogs()} />
                        ))}
                    </tbody>
                </table>
            </div>
        );
    }
}
