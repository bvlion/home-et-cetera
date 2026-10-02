# 開発用Composeプロジェクト名（compose.yamlの既定値と同じ値を明示している
# だけで、挙動は変わらない）。db-wipeが開発用DB volumeをlabelで一意に特定
# するために使用する。checkターゲットはこの変数を一切参照しない。
DEV_PROJECT = bvlionbatch5

# 検証用（make check）専用のCompose project名。開発用とは常に異なる名前を
# 使うことで、container・network・volume・host portが開発環境と混ざらない
# ようにする。
CHECK_PROJECT = bvlionbatch5-check

# 検証専用のCompose構成（compose.check.yaml）を、検証専用のproject名・
# --env-file .env.example で実行する。Composeの変数展開にも実.envを使わない。
CHECK_COMPOSE = docker compose -f compose.check.yaml -p $(CHECK_PROJECT) --env-file .env.example

.PHONY: check check-clean db-wipe

# 開発用のcontainer・network・volume・host portには一切触れず、
# compose.check.yaml を検証専用のCompose project ($(CHECK_PROJECT)) で
# 実行する。成功・失敗にかかわらず、最後に検証専用projectだけをcleanupする。
check:
	trap '$(CHECK_COMPOSE) down --volumes --remove-orphans' EXIT; \
	$(CHECK_COMPOSE) build app && \
	$(CHECK_COMPOSE) run --rm --no-deps app composer validate && \
	$(CHECK_COMPOSE) run --rm --no-deps app composer install --prefer-dist --no-progress && \
	$(CHECK_COMPOSE) run --rm --no-deps app composer lint && \
	$(CHECK_COMPOSE) run --rm --no-deps app composer style && \
	$(CHECK_COMPOSE) run --rm app composer migrate && \
	$(CHECK_COMPOSE) run --rm app composer test

# make check が失敗などで異常終了しtrapが働かなかった場合の手動cleanup用。
# 検証専用projectだけを対象とし、開発用のcontainer・network・volumeには
# 触れない。
check-clean:
	$(CHECK_COMPOSE) down --volumes --remove-orphans

# 開発用DBのvolumeを削除する（既存データを完全に削除する）破壊的な操作。
# 誤実行防止のため CONFIRM=yes を必須にする。
#
# 開発用Composeプロジェクトに対する `down --volumes` は使わない（appコンテナ
# を含む全サービスに影響しうるため）。代わりに、Composeが自動付与する
# `com.docker.compose.project` / `com.docker.compose.volume` ラベルで
# 開発用DB volumeを1件だけ特定し、そのvolumeだけを明示的に削除する。
# 該当が0件または複数件の場合は、削除を行わずエラーで停止する。
# appコンテナや検証用Composeプロジェクトには一切触れない。
# 削除後にDBを再作成する場合は、docs/BvlionBatch5/README.md「データベースとマイグレーション」節の
# 手順を実行すること（本ターゲットはDBの再作成・再マイグレーションを行わない）。
db-wipe:
	@if [ "$(CONFIRM)" != "yes" ]; then \
		echo "この操作は開発用データベース(project=$(DEV_PROJECT))のデータを完全に削除します。" >&2; \
		echo "実行する場合は次のように明示的に指定してください: make db-wipe CONFIRM=yes" >&2; \
		exit 1; \
	fi
	@volume="$$(docker volume ls \
		--filter "label=com.docker.compose.project=$(DEV_PROJECT)" \
		--filter "label=com.docker.compose.volume=database" \
		--format '{{.Name}}')"; \
	count="$$(printf '%s\n' "$$volume" | grep -c .)"; \
	if [ "$$count" -ne 1 ]; then \
		echo "[中止] 開発用DB(project=$(DEV_PROJECT))のvolumeを1件だけ特定できませんでした（該当: $$count 件）。" >&2; \
		exit 1; \
	fi; \
	echo "削除対象volume: $$volume"; \
	if ! docker compose -p $(DEV_PROJECT) stop database; then \
		echo "[中止] 開発用databaseサービスの停止に失敗しました。" >&2; \
		exit 1; \
	fi; \
	if ! docker compose -p $(DEV_PROJECT) rm --force database; then \
		echo "[中止] 開発用databaseコンテナの削除に失敗しました。" >&2; \
		exit 1; \
	fi; \
	if ! docker volume rm "$$volume"; then \
		echo "[中止] volume $$volume の削除に失敗しました。DBは初期化されていません。" >&2; \
		exit 1; \
	fi; \
	if docker volume inspect "$$volume" >/dev/null 2>&1; then \
		echo "[中止] volume $$volume の削除を確認できませんでした。DBは初期化されていません。" >&2; \
		exit 1; \
	fi; \
	echo "開発用DBのvolume($$volume)を削除しました。再作成するにはdocs/BvlionBatch5/README.md「データベースとマイグレーション」節の手順を実行してください。"
