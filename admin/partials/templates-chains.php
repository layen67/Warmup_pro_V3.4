<div id="pw-chains-tab" class="pw-tab-content" style="display:none;">
    <div class="pw-chains-toolbar">
        <p class="description">
            Visualisez et gérez vos séquences de réponse automatique (Threads).
            Les chaînes sont détectées automatiquement basées sur le suffixe <code>_reply</code>.
        </p>
    </div>
    <div id="pw-chains-container" class="pw-chains-grid">
        <!-- JS injected -->
        <div class="pw-loading"><span class="spinner is-active"></span> Chargement...</div>
    </div>
</div>

<style>
.pw-chains-grid { padding: 20px 0; }
.pw-chain-row { background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 20px; border-radius: 4px; }
.pw-chain-header { display: flex; justify-content: space-between; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
.pw-chain-flow { display: flex; align-items: center; gap: 10px; overflow-x: auto; padding-bottom: 10px; }
.pw-chain-step { display: flex; align-items: center; }
.pw-chain-card { border: 1px solid #e5e5e5; border-radius: 4px; padding: 10px; min-width: 180px; background: #f9f9f9; text-align: center; }
.pw-chain-step.active .pw-chain-card { background: #fff; border-color: #2271b1; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
.pw-chain-step.missing .pw-chain-card { border-style: dashed; opacity: 0.7; }
.pw-chain-arrow { font-size: 20px; color: #ccc; padding: 0 10px; }
.pw-chain-name { display: block; font-weight: 600; margin-bottom: 5px; font-size: 12px; }
.pw-chain-icon { font-size: 20px; margin-bottom: 5px; }
</style>
