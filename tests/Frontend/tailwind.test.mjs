import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile, readdir } from 'node:fs/promises'
import { parse } from '@vue/compiler-sfc'
import postcss from 'postcss'
import ts from 'typescript'

const root = new URL('../../', import.meta.url)
async function sourceFiles(directory, extension) {
  const entries = await readdir(directory, { withFileTypes: true })
  const groups = await Promise.all(entries.map(entry => entry.isDirectory() ? sourceFiles(new URL(entry.name + '/', directory), extension) : entry.name.endsWith(extension) ? [new URL(entry.name, directory)] : []))
  return groups.flat()
}

test('dashboard styling contains only Tailwind framework and theme directives', async () => {
  const source = await readFile(new URL('resources/css/app.css', root), 'utf8')
  for (const file of await sourceFiles(new URL('resources/css/', root), '.css')) {
    const styles = await readFile(file, 'utf8')
    const stylesheet = postcss.parse(styles)
    stylesheet.walkRules(rule => assert.fail(`Handwritten dashboard CSS selector in ${file.pathname}: ${rule.selector}`))
    stylesheet.walkAtRules(rule => assert.ok(['layer', 'import', 'source', 'custom-variant', 'theme'].includes(rule.name), `Unexpected style directive: @${rule.name}`))
    assert.doesNotMatch(styles, /@apply\b/)
  }
  assert.match(source, /tailwindcss\/utilities\.css/)
  assert.match(source, /--radius-sm:\s*\.25rem/)
  assert.match(source, /--spacing-control:\s*38px/)
})

test('every Vue dashboard surface uses utility classes rather than style blocks or inline styles', async () => {
  const files = await sourceFiles(new URL('resources/js/', root), '.vue')
  assert.ok(files.length > 0, 'The dashboard audit must discover Vue files')
  for (const file of files) {
    const source = await readFile(file, 'utf8')
    const { descriptor, errors } = parse(source)
    assert.equal(errors.length, 0, file.pathname)
    assert.equal(descriptor.styles.length, 0, `Style block in ${file.pathname}`)
    function inspect(node) {
      if (node.type === 1) for (const prop of node.props) {
        const name = prop.type === 6 ? prop.name : prop.type === 7 && prop.name === 'bind' ? prop.arg?.content : null
        assert.notEqual(name, 'style', `Inline styling in ${file.pathname}`)
        assert.ok(!['fill', 'stroke', 'stroke-width', 'text-anchor'].includes(name), `Move SVG presentation to Tailwind utilities in ${file.pathname}`)
      }
      for (const child of node.children ?? []) inspect(child)
    }
    descriptor.template.ast.children.forEach(inspect)
    assert.doesNotMatch(source, /\.style\.(?:setProperty|cssText|left|top|width|height)\b/, `Runtime CSS mutation in ${file.pathname}`)
  }
  const shell = await readFile(new URL('resources/views/shell.blade.php', root), 'utf8')
  assert.doesNotMatch(shell, /<style\b|\sstyle\s*=/i)
})

const source = await readFile(new URL('resources/js/ui.ts', root), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText
  .replace(/from ['"]tailwind-merge['"]/g, 'from ' + JSON.stringify(import.meta.resolve('tailwind-merge')))
const { ui } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'))

test('dashboard utility variables resolve to declared theme tokens', async () => {
  const css = postcss.parse(await readFile(new URL('resources/css/app.css', root), 'utf8'))
  const known = new Set()
  css.walkDecls(declaration => { if (declaration.prop.startsWith('--')) known.add(declaration.prop) })
  const shell = await readFile(new URL('resources/views/shell.blade.php', root), 'utf8')
  const utilities = source + '\n' + shell
  for (const match of utilities.matchAll(/\[(--[\w-]+):/g)) known.add(match[1])
  for (const match of utilities.matchAll(/var\((--[\w-]+)/g)) assert.ok(known.has(match[1]), `Missing theme token: ${match[1]}`)
})

test('utility recipes preserve DOM hooks and Vue conditional bindings', () => {
  const classes = ui('$a', 'text-link', [null, false, { selected: true, disabled: false }]).split(/\s+/)
  assert.ok(classes.includes('text-link'), 'Tailwind merging must not erase a styling or interaction hook')
  assert.ok(classes.includes('selected'))
  assert.ok(!classes.includes('disabled'))
  assert.ok(!classes.includes('$a'))
})

test('shared typography and state recipes retain explicit overrides', () => {
  const button = ui('$button', 'button').split(/\s+/)
  assert.ok(button.includes('font-medium'))
  assert.ok(!button.includes('font-[number:inherit]'))
  const caption = ui('$p', 'data-caption error-list-caption').split(/\s+/)
  assert.ok(caption.includes('leading-[1.8]'), 'Changing font size must not discard the explicit line height')
  assert.ok(caption.includes('[font-size:12px]'))
  assert.ok(ui('status-badge status-pending').includes('border-tm-warning-border'))
})

test('all dashboard tables share their table/cell recipes and accessible column headers', async () => {
  let tables = 0
  for (const file of await sourceFiles(new URL('resources/js/', root), '.vue')) {
    const { descriptor } = parse(await readFile(file, 'utf8'))
    function inspect(node, inTable = false, inHead = false) {
      if (node.type === 1) {
        inTable ||= node.tag === 'table'
        inHead ||= node.tag === 'thead'
        if (node.tag === 'table') {
          tables++
          assert.ok(node.children.some(child => child.type === 1 && child.tag === 'caption'), `Table needs a caption: ${file.pathname}`)
        }
        if (inTable && ['table', 'th', 'td'].includes(node.tag)) {
          const classes = node.props.find(prop => prop.type === 7 && prop.name === 'bind' && prop.arg?.content === 'class')
          assert.ok(classes?.exp?.content.includes(`'$${node.tag}'`), `Use the shared ${node.tag} recipe: ${file.pathname}`)
        }
        if (inHead && node.tag === 'th') assert.equal(node.props.find(prop => prop.type === 6 && prop.name === 'scope')?.value?.content, 'col', `Header scope in ${file.pathname}`)
      }
      for (const child of node.children ?? []) inspect(child, inTable, inHead)
    }
    descriptor.template.ast.children.forEach(child => inspect(child))
  }
  assert.ok(tables > 0)
})
