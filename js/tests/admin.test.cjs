const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

test('distributed admin bundle registers and renders settings through the Flarum 2 registry', () => {
    const composer = JSON.parse(fs.readFileSync(path.join(__dirname, '../../composer.json'), 'utf8'));
    const [vendor, packageName] = composer.name.split('/');
    const extensionId = `${vendor}-${packageName.replace(/flarum-ext-|flarum-/g, '')}`;
    const initializers = [];
    const beforeMountCallbacks = [];
    const pages = new Map();

    class Component {}
    class ExtensionPage extends Component {
        oninit() {}
        setting() {
            return () => '';
        }
        submitButton() {
            return 'save';
        }
    }

    const app = {
        initializers: {
            add(name, initializer) {
                initializers.push(initializer);
            },
        },
        beforeMount(callback) {
            beforeMountCallbacks.push(callback);
        },
        registry: {
            for(id) {
                return { registerPage: (page) => pages.set(id, page) };
            },
        },
        translator: { trans: (key) => key },
    };
    const modules = new Map([
        ['admin/app', app],
        ['admin/components/ExtensionPage', ExtensionPage],
        ['common/Component', Component],
        ['common/components/Button', Component],
        ['common/components/Switch', Component],
        ['common/components/Select', Component],
    ]);
    const registry = {
        get(namespace, name) {
            assert.equal(namespace, 'core');
            assert.ok(modules.has(name), `Unexpected core module: ${name}`);
            return modules.get(name);
        },
        add(namespace, name, component) {
            assert.equal(namespace, extensionId);
            modules.set(`${namespace}/${name}`, component);
        },
    };

    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../dist/admin.js'), 'utf8'), {
        flarum: { reg: registry },
        module: { exports: {} },
        m: (tag, attrs, ...children) => ({ tag, attrs, children }),
    });

    assert.equal(initializers.length, 1);
    initializers.forEach((initialize) => initialize());
    assert.equal(pages.size, 0);
    assert.equal(beforeMountCallbacks.length, 1);
    beforeMountCallbacks.forEach((callback) => callback());
    const Page = pages.get(extensionId);
    assert.ok(Page, `No settings page registered for ${extensionId}`);
    const page = new Page();
    assert.ok(page instanceof ExtensionPage);
    page.oninit({ attrs: { id: extensionId } });
    const content = page.content();
    assert.equal(content.attrs.className, 'ExtensionPage-settings');
    const container = content.children[0];
    assert.equal(container.attrs.className, 'container');
    assert.equal(container.children[0].attrs.className, 'ModerationSettingsPage');
    assert.ok(JSON.stringify(content).includes('"type":"password"'));
    assert.ok(JSON.stringify(content).includes('tapao-moderationai.admin.settings.api_key_label'));
    assert.ok(JSON.stringify(content).includes('tapao-moderationai.admin.settings.thresholds_heading'));
});

test('distributed forum bundle labels AI flags and preserves other flag reasons', () => {
    const initializers = [];
    class Post {}
    const app = {
        initializers: {
            add(name, initialize, priority) {
                initializers.push({ initialize, priority });
            },
        },
        translator: {
            trans(key) {
                assert.equal(key, 'tapao-moderationai.forum.ai_flagged');
                return 'AI flagged';
            },
        },
    };
    const modules = new Map([
        ['forum/app', app],
        ['forum/components/Post', Post],
        ['common/extend', {
            override(target, method, callback) {
                const original = target[method];
                target[method] = function (...args) {
                    return callback.call(this, original.bind(this), ...args);
                };
            },
        }],
    ]);

    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../dist/forum.js'), 'utf8'), {
        flarum: {
            reg: {
                get(namespace, name) {
                    assert.equal(namespace, 'core');
                    assert.ok(modules.has(name), `Unexpected core module: ${name}`);
                    return modules.get(name);
                },
            },
        },
        module: { exports: {} },
    });

    assert.equal(initializers.length, 1);
    assert.ok(initializers[0].priority < 0);
    assert.doesNotThrow(() => initializers[0].initialize());
    assert.equal(Post.prototype.flagReason, undefined);

    Post.prototype.flagReason = function (flag) {
        return `${this.author}: ${flag.type()}`;
    };
    initializers[0].initialize();
    const post = new Post();
    post.author = 'Moderator';
    assert.equal(post.flagReason({ type: () => 'ai_moderation' }), 'AI flagged');
    assert.equal(post.flagReason({ type: () => 'user' }), 'Moderator: user');
    assert.equal(post.flagReason({ type: () => 'approval' }), 'Moderator: approval');
});