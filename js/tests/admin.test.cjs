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
    assert.equal(content.attrs.className, 'ModerationSettingsPage');
    assert.ok(JSON.stringify(content).includes('"type":"password"'));
    assert.ok(JSON.stringify(content).includes('tapao-moderationai.admin.settings.api_key_label'));
    assert.ok(JSON.stringify(content).includes('tapao-moderationai.admin.settings.thresholds_heading'));
});