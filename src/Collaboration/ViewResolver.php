<?php

namespace TelescopeMonitor\Collaboration;

use TelescopeMonitor\Repositories\Contracts\CollaborationRepository;

final class ViewResolver
{
    public function __construct(private CollaborationRepository $views, private ViewDefinition $definitions) {}

    public function resolve(array $query): array
    {
        if (! $this->views->ready()) {
            if (isset($query['view'])) {
                throw new Conflict('Publish and run the collaboration migration before opening saved views.');
            }

            return $query;
        }
        if (! $query) {
            $ref = $this->views->preferences()['default_view_ref'];
            if ($ref !== null) {
                $builtins = $this->definitions->builtins();
                if (isset($builtins[$ref])) {
                    return $builtins[$ref];
                }$query['view'] = $ref;
            }
        }
        if (! isset($query['view'])) {
            return $query;
        }
        $view = $this->views->view($query['view']);
        if ($view['state'] !== 'ready') {
            throw new Conflict('This saved view needs repair. Replace its filters in Saved views or clear the view.', ['view' => $view['id']]);
        }
        $explicit = array_diff(array_keys($query), ['view', 'page', 'per_page']);
        if (! $explicit) {
            $query = array_merge($view['definition']['filters'], ['range' => $view['definition']['range'], 'sort' => $view['definition']['sort']], $query);
        }

        return $query;
    }
}
