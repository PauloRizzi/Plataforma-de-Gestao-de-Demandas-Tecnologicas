/**
 * Combos com pesquisa (Fase 9): todo <select class="form-select"> ganha uma caixa de busca,
 * para achar o registro quando a lista é grande. O <select> original continua no formulário
 * (escondido) e é ele que vai no envio; o combo só troca o valor e dispara o evento "change".
 * Opções escondidas ou desativadas por outro script (ex.: demandantes de outra escola) não aparecem.
 * Para manter o combo nativo, use `data-sem-pesquisa` no <select>.
 */

let contador = 0;

/** Compara sem diferenciar maiúsculas e acentos ("sao jose" acha "São José"). */
function normalizar(texto) {
    return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

function textoDoBotao(select) {
    const escolhidas = Array.from(select.selectedOptions).filter((opcao) => opcao.value !== '');

    if (!select.multiple) {
        return select.selectedOptions[0]?.textContent.trim() || select.options[0]?.textContent.trim() || '';
    }

    if (escolhidas.length === 0) {
        return select.dataset.placeholder ?? 'Nenhum selecionado';
    }

    return escolhidas.length <= 2
        ? escolhidas.map((opcao) => opcao.textContent.trim()).join(', ')
        : `${escolhidas.length} selecionados`;
}

function criarCombo(select) {
    const id = `combo-${++contador}`;
    const pequeno = select.classList.contains('form-select-sm');

    const combo = document.createElement('div');
    combo.className = 'combo-pesquisa';

    const botao = document.createElement('button');
    botao.type = 'button';
    botao.id = `${id}-botao`;
    botao.className = `form-select text-start text-truncate${pequeno ? ' form-select-sm' : ''}`;
    botao.setAttribute('aria-haspopup', 'listbox');
    botao.setAttribute('aria-expanded', 'false');

    const painel = document.createElement('div');
    painel.className = 'combo-pesquisa-painel d-none';

    const busca = document.createElement('input');
    busca.type = 'search';
    busca.className = 'form-control form-control-sm';
    busca.placeholder = 'Pesquisar...';
    busca.autocomplete = 'off';
    busca.setAttribute('role', 'combobox');
    busca.setAttribute('aria-controls', `${id}-lista`);
    busca.setAttribute('aria-expanded', 'true');

    const lista = document.createElement('ul');
    lista.id = `${id}-lista`;
    lista.className = 'list-unstyled mb-0';
    lista.setAttribute('role', 'listbox');
    if (select.multiple) {
        lista.setAttribute('aria-multiselectable', 'true');
    }

    const vazio = document.createElement('div');
    vazio.className = 'small text-muted px-2 py-1 d-none';
    vazio.textContent = 'Nada encontrado.';

    painel.append(busca, lista, vazio);
    combo.append(botao, painel);
    select.after(combo);

    // O <select> fica escondido, mas continua no formulário; o rótulo passa a apontar para o botão.
    select.classList.add('d-none');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    const rotulo = select.id ? document.querySelector(`label[for="${CSS.escape(select.id)}"]`) : null;
    if (rotulo) {
        rotulo.htmlFor = botao.id;
        botao.setAttribute('aria-labelledby', `${rotulo.id || (rotulo.id = `${id}-rotulo`)} ${botao.id}`);
    }

    let ativo = -1;

    const itens = () => Array.from(lista.querySelectorAll('li:not(.d-none)'));

    const sincronizarBotao = () => {
        botao.textContent = textoDoBotao(select);
        botao.classList.toggle('is-invalid', select.classList.contains('is-invalid'));
        botao.disabled = select.disabled;
    };

    const marcarAtivo = (indice) => {
        const visiveis = itens();
        visiveis.forEach((item) => item.classList.remove('ativo'));
        ativo = Math.max(0, Math.min(indice, visiveis.length - 1));
        const item = visiveis[ativo];

        if (item) {
            item.classList.add('ativo');
            item.scrollIntoView({ block: 'nearest' });
            busca.setAttribute('aria-activedescendant', item.id);
        } else {
            busca.removeAttribute('aria-activedescendant');
        }
    };

    const filtrar = () => {
        const termo = normalizar(busca.value);
        let algum = false;

        lista.querySelectorAll('li').forEach((item) => {
            const mostra = termo === '' || item.dataset.busca.includes(termo);
            item.classList.toggle('d-none', !mostra);
            algum ||= mostra;
        });

        vazio.classList.toggle('d-none', algum);
        marcarAtivo(0);
    };

    const montarLista = () => {
        lista.replaceChildren(...Array.from(select.options)
            .filter((opcao) => !opcao.hidden && !opcao.disabled && !(select.multiple && opcao.value === ''))
            .map((opcao, indice) => {
                const item = document.createElement('li');
                item.id = `${id}-opcao-${indice}`;
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', opcao.selected ? 'true' : 'false');
                item.dataset.valor = opcao.value;
                item.dataset.busca = normalizar(opcao.textContent);
                item.textContent = opcao.textContent.trim();
                item.classList.toggle('escolhido', opcao.selected && (select.multiple || opcao.value !== ''));
                item.classList.toggle('text-muted', opcao.value === '');
                return item;
            }));
    };

    const posicionar = () => {
        const area = botao.getBoundingClientRect();
        const alturaLivreAbaixo = window.innerHeight - area.bottom;
        painel.style.minWidth = `${area.width}px`;
        painel.style.left = `${Math.max(8, Math.min(area.left, window.innerWidth - painel.offsetWidth - 8))}px`;

        // Abre para cima quando não cabe embaixo (ex.: combo no fim da página ou num modal).
        if (alturaLivreAbaixo < 260 && area.top > alturaLivreAbaixo) {
            painel.style.top = '';
            painel.style.bottom = `${window.innerHeight - area.top + 2}px`;
        } else {
            painel.style.bottom = '';
            painel.style.top = `${area.bottom + 2}px`;
        }
    };

    const fechar = (devolverFoco = true) => {
        if (painel.classList.contains('d-none')) {
            return;
        }

        painel.classList.add('d-none');
        botao.setAttribute('aria-expanded', 'false');
        window.removeEventListener('scroll', posicionar, true);
        window.removeEventListener('resize', posicionar);
        if (devolverFoco) {
            botao.focus();
        }
    };

    const abrir = () => {
        montarLista();
        busca.value = '';
        filtrar();
        painel.classList.remove('d-none');
        botao.setAttribute('aria-expanded', 'true');
        posicionar();
        const escolhido = itens().findIndex((item) => item.classList.contains('escolhido'));
        marcarAtivo(escolhido >= 0 ? escolhido : 0);
        busca.focus();
        window.addEventListener('scroll', posicionar, true);
        window.addEventListener('resize', posicionar);
    };

    const escolher = (item) => {
        const opcao = Array.from(select.options).find((candidata) => candidata.value === item.dataset.valor);

        if (!opcao) {
            return;
        }

        if (select.multiple) {
            opcao.selected = !opcao.selected;
            item.classList.toggle('escolhido', opcao.selected);
            item.setAttribute('aria-selected', opcao.selected ? 'true' : 'false');
        } else {
            select.value = opcao.value;
        }

        select.dispatchEvent(new Event('change', { bubbles: true }));

        if (!select.multiple) {
            fechar();
        }
    };

    botao.addEventListener('click', () => (painel.classList.contains('d-none') ? abrir() : fechar()));
    botao.addEventListener('keydown', (evento) => {
        if (['ArrowDown', 'ArrowUp'].includes(evento.key) || (evento.key.length === 1 && !evento.ctrlKey && !evento.metaKey)) {
            evento.preventDefault();
            abrir();
            if (evento.key.length === 1) {
                busca.value = evento.key;
                filtrar();
            }
        }
    });

    busca.addEventListener('input', filtrar);
    busca.addEventListener('keydown', (evento) => {
        if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
            evento.preventDefault();
            marcarAtivo(ativo + (evento.key === 'ArrowDown' ? 1 : -1));
        } else if (evento.key === 'Enter') {
            evento.preventDefault();
            const item = itens()[ativo];
            if (item) {
                escolher(item);
            }
        } else if (evento.key === 'Escape') {
            evento.preventDefault();
            evento.stopPropagation();
            fechar();
        } else if (evento.key === 'Tab') {
            fechar(false);
        }
    });

    // mousedown: escolhe antes de a busca perder o foco.
    lista.addEventListener('mousedown', (evento) => {
        const item = evento.target.closest('li');
        if (item) {
            evento.preventDefault();
            escolher(item);
        }
    });

    document.addEventListener('mousedown', (evento) => {
        if (!combo.contains(evento.target)) {
            fechar(false);
        }
    });

    // Outros scripts mudam o valor (e a validação marca o erro) e avisam com "change".
    select.addEventListener('change', sincronizarBotao);
    select.form?.addEventListener('reset', () => setTimeout(sincronizarBotao));
    new MutationObserver(sincronizarBotao).observe(select, { attributes: true, attributeFilter: ['class', 'disabled'] });

    sincronizarBotao();
}

export function iniciarCombosComPesquisa(raiz = document) {
    raiz.querySelectorAll('select.form-select:not([data-sem-pesquisa]):not([data-combo-pronto])').forEach((select) => {
        select.dataset.comboPronto = '1';
        criarCombo(select);
    });
}
