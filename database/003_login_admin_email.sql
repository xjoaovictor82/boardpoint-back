-- Login do admin por e-mail. Seguro rodar mais de uma vez.
ALTER TABLE admins ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL AFTER usuario;

-- O admin que já existe ganha um e-mail (troque depois, se quiser)
UPDATE admins SET email = 'admin@boardpoint.com' WHERE usuario = 'admin' AND email IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_admins_email ON admins (email);