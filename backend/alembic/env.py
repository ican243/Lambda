from logging.config import fileConfig

from sqlalchemy import engine_from_config
from sqlalchemy import pool

from alembic import context
from app.database import Base, SQLALCHEMY_DATABASE_URL

# this is the Alembic Config object, which provides
# access to the values within the .ini file in use.
config = context.config
config.set_main_option(
    "sqlalchemy.url",
    SQLALCHEMY_DATABASE_URL
)

# Interpret the config file for Python logging.
# This line sets up loggers basically.
if config.config_file_name is not None:
    fileConfig(config.config_file_name)

# add your model's MetaData object here
# for 'autogenerate' support
# from myapp import mymodel
# target_metadata = mymodel.Base.metadata
import sys
import os
sys.path.append(os.getcwd())  # backend 폴더를 path에 추가

from app.database import Base
from app import models  # noqa: F401  (모든 모델을 등록하기 위해 import)

target_metadata = Base.metadata

# 팀원(PHP)이 관리하는 테이블 - Alembic이 생성/변경/삭제하지 않도록 제외
PHP_MANAGED_TABLES = {
    "access_logs", "accounts", "admin_cash_logs", "admins",
    "app_settings", "auto_trade_settings", "error_logs", "holdings", "notices",
    "orders", "index_candles_1d", "stock_candles_1d", "stock_candles_1m",
    "stock_latest", "stock_logs", "stock_master", "stock_posts", "stock_views",
    "users", "watchlist", 
}


def include_object(object, name, type_, reflected, compare_to):
    if type_ == "table" and name in PHP_MANAGED_TABLES:
        return False
    # 팀원 테이블에 딸린 인덱스/제약조건도 같이 제외
    if type_ in ("index", "unique_constraint") and object.table is not None:
        if object.table.name in PHP_MANAGED_TABLES:
            return False
    return True

# other values from the config, defined by the needs of env.py,
# can be acquired:
# my_important_option = config.get_main_option("my_important_option")
# ... etc.


def run_migrations_offline() -> None:
    url = config.get_main_option("sqlalchemy.url")
    context.configure(
        url=url,
        target_metadata=target_metadata,
        literal_binds=True,
        dialect_opts={"paramstyle": "named"},
        include_object=include_object,   # 추가
    )
    with context.begin_transaction():
        context.run_migrations()


def run_migrations_online() -> None:
    connectable = engine_from_config(
        config.get_section(config.config_ini_section, {}),
        prefix="sqlalchemy.",
        poolclass=pool.NullPool,
    )
    with connectable.connect() as connection:
        context.configure(
            connection=connection,
            target_metadata=target_metadata,
            include_object=include_object,   # 추가
        )
        with context.begin_transaction():
            context.run_migrations()


if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()
